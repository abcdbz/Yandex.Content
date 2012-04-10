<?php

/**
 * Yandex.Webmaster class for post articles
 *
 * How to use:
 * make instance: $yc = new YandexContent('mylogin', 'mypass');
 * set your encoding: $yc->setInputEncoding('cp1251');
 * ..and post: $result = $yc->postArticle($article, 'www.mysite.ru');
 */

class YandexContent {
    const OUTPUT_ENCODING = 'UTF-8';
	const TEXT_LENGTH_MIN = 2000;
	const TEXT_LENGTH_MAX = 32000;

	private $errmsg;
	private $errcode;

	private $login;
    private $passwd;
    private $cookie_filename;
    private $curl_handler;
    private $base_url = 'http://webmaster.yandex.ru/';
    private $login_url = 'https://passport.yandex.ru/passport?mode=auth&from=webmaster';
    private $input_encoding;

    /**
     *
     * @param string $login
     * @param string $pass
	 * @param boolean $use_pconn Whether or not to save cookie file for permanent curl session
     */
    public function __construct($login, $pass, $use_pconn = true) {
        $this->login = $login;
        $this->passwd = $pass;
		$tmpdir = sys_get_temp_dir() . '/';
		$this->cookie_filename = ($use_pconn ? $tmpdir . md5($login . $pass) : tempnam($tmpdir , ''));
		if (!touch($this->cookie_filename) or !is_writable($this->cookie_filename)) {
			return $this->error('Cant write to curl cookie file [' . $this->cookie_filename . ']', 1);
		}
		if (!$this->isLoggedIn() and !$this->logIn()) {
			return $this->error('Failed to log in to yandex.webmaster', 8);
		}
    }
	public function __destruct() {
		if ($this->curl_handler) {
			curl_close($this->curl_handler);
		}
	}
    /**
     * Set encoding, which you use in your system
     *
     * @param string $encoding
     */
    public function setInputEncoding($encoding) {
        $this->input_encoding = $encoding;
    }
    /**
     *
     * @param string $cookie_filename Real path to cookie filename with write access
     */
    public function setCurlCookieFilename($cookie_filename) {
        $this->cookie_filename = $cookie_filename;
    }
    /**
     * post credentials to yandex login page
     * call it before first use or if is_logged_in() == false
     *
     * @return boolean Logged in or not
     */
    public function logIn() {
        $postfields = 'login=' . $this->getLogin() . '&passwd=' . $this->getPasswd();
        $curl = $this->getCurlHandler();

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_URL, $this->getLoginUrl());
        $result = curl_exec($curl);
        curl_setopt($curl, CURLOPT_POST, false);
		if ($result === false) {
			return $this->error('cURL error: ' . curl_error($curl), curl_errno($curl));
		}
        if (!$this->isLoggedIn()) {
			return $this->error('Login failed', 5);
		}
		return true;
    }
    /**
     * Check, if already logged in
     *
     * @return boolean
     */
    public function isLoggedIn() {
        $curl = $this->getCurlHandler();
        curl_setopt($curl, CURLOPT_URL, $this->getBaseUrl());
        $result = curl_exec($curl);
		if ($result === false) {
			return $this->error('cURL error: ' . curl_error($curl), curl_errno($curl));
		}
        if (strpos($result, 'http://passport.yandex.ru/passport?mode=logout') === false) {
			return $this->error('Not logged in, logout button not found', 6);
		}
		return true;
    }
    /**
     *
     * @param boolean $only_approved
     * @return string[]
     */
    public function getSiteList($only_approved = true) {
        $curl = $this->getCurlHandler();
        curl_setopt($curl, CURLOPT_URL, $this->getBaseUrl());
        $page = curl_exec($curl);
		if ($page === false) {
			return $this->error('cURL error: ' . curl_error($curl), curl_errno($curl));
		}
        $pattern = '~mvc\.map\(.*,\[(".*)\]~Ui';
        if ( !preg_match_all($pattern, $page, $matches) || !isset($matches[1][1]) ) {
            return $this->error('Site list not found in response', 2);
        }
		// :FIX: Silvery 25.01.2012
		// Yandex code for site list were changed	
		if ( !preg_match_all('~"(.*)","(.*)","(.*)",(.*)(?=,)~U', $matches[1][1], $sitematch, PREG_SET_ORDER) ) {
            return $this->error('No sites found', 2);
        }
        $found = array();
        foreach ($sitematch as $idx => $site) {
            $is_approved = ($site[4] == 'true');
            if ($is_approved or !$only_approved) {
                $found[$site[1]] = $site[3];
            }
        }
		// END:FIX:
        return $found;
    }
    /**
     * Post article and link it to site name
     *
     * @param string $text
     * @param string $site_name
     * @return boolean
     */
    public function postArticle($text, $site_name) {
		$text = strip_tags($text);
		$text = trim($text);
		if ( empty($site_name) ) {
			return $this->error('Empty site name not allowed', 3);
		}
		if ( ($len = mb_strlen($text)) < self::TEXT_LENGTH_MIN ) {
			return $this->error('Wrong text size (' . $len . '). Must be greater than ' .
				self::TEXT_LENGTH_MIN, 3);
		}
		// :FIX: Silvery 24.01.2012
		// Split text into parts if needed
		$texts = $this->splitTextByLength($text);
		if ( !$texts ) {
			return false;
		}

		foreach ( $texts AS $val ) {
			$res = $this->sendArticleText($val, $site_name);
			if ( !$res ) {
				return false;
			}
		}
		return true;
    }
	/**
	 * :ADD: Silvery 24.01.2012
	 * Split text into readable parts with length less than TEXT_LENGTH_MAX each
	 * @param string $text
	 * @return mixed 
	 */
	private function splitTextByLength($text) {
		$encoding = $this->getInputEncoding($text);
		mb_internal_encoding($encoding);
		
		$result = array(); 
		$i = 0;
		$match = '~^(\n*)[A-ZÀ-ß0-9]~';
		// Rather cruel but still...
		if ($encoding == 'utf-8') {
			$match = iconv('cp1251', 'utf-8', $match . 'u');
		}
		// Additional condition to avoid endless loop
		$limit = mb_strlen($text) / self::TEXT_LENGTH_MAX + 1;
		while ( mb_strlen($text) > self::TEXT_LENGTH_MAX && $i < $limit ) {
			$result[$i] = ltrim( mb_substr($text, 0, self::TEXT_LENGTH_MAX) );
			$text = mb_substr($text, self::TEXT_LENGTH_MAX);
			// If tail is less than TEXT_LENGTH_MIN - add some text
			if ( ($len = mb_strlen($text)) < self::TEXT_LENGTH_MIN ) {
				$text = mb_substr($result[$i], $len - self::TEXT_LENGTH_MIN) . $text;
				$result[$i] = mb_substr($result[$i], 0, $len - self::TEXT_LENGTH_MIN);
			}
			// Find and move endless sentence from the ending
			$noend = true;
			$subcheck = false;
			$k = 0;
			// Additional condition to avoid endless loop (1000 symbols should be enough)
			while ( $noend && $k < 10 ) {
				$k++;
				$part = mb_substr($result[$i], -100);
				// Need only spaces after dot instead of variety of special symbols
				$lpart = str_replace(array("\n", "\r", "\t"), ' ', $part);
				// Check last symbol if previous part started with uppercase or number
				if ( $subcheck ) {
					if ( mb_substr( rtrim($lpart), -1) == '.' ) {
						$noend = false;
						continue;
					} else {
						$subcheck = false;
					}
				}
				$part_split = explode('. ', $lpart);

				$add = array();
				// Check for uppercase or number in the beginning of each part
				while ( ($part_item = array_pop($part_split)) && $noend ) {
					array_unshift($add, $part_item);
					if ( preg_match($match, $part_item) ) {
						if ( count($part_split) > 0 ) {
							// Not first - there was dot before
							$part = implode('. ', $add);
							$noend = false;
						} else {
							// Need to check previous symbols - on the next step
							$subcheck = true;
						}
					} 
				}
				$text = $part . $text;
				$result[$i] = mb_substr($result[$i], 0, mb_strlen($result[$i]) - mb_strlen($part));
			}
			if ( $noend ) {
				return $this->error('Can`t split text into parts', 12);
			}
			$i++;
		}
		$result[$i] = $text;
		return $result;
	}
	/**
	 * Actually send text
	 * @param string $text
	 * @param string $site_name
	 * @return type 
	 */
	private function sendArticleText($text, $site_name) {
		$text_head = mb_substr($text, 0, 100); // for post check
        $text = $this->convertEncoding($text);
        $postfields = array(
            'action' => 'saveData',
            'host' => $site_name,
            #'mvcDataLoadSignature' => 'saveData',
            'page' => 'Originals-submission-form',
            'service' => 'ORIGINALS',
            'wsw-fields' => '<wsw-fields>' .
                '<wsw-field name="host"><wsw-value>' . $site_name . '</wsw-value></wsw-field>' .
                '<wsw-field name="Original_text"><wsw-value>' . htmlspecialchars($text) . '</wsw-value></wsw-field>' .
                '</wsw-fields>'
        );
        $curl = $this->getCurlHandler();
        curl_setopt($curl, CURLOPT_URL, 'http://webmaster.yandex.ru/site/plugins/wsw.api/api.xml');
		curl_setopt($curl, CURLOPT_HEADER, 1);
		$i = 3; // tries counter
		while ($i--) {
	        curl_setopt($curl, CURLOPT_POST, true);
			// CURLOPT_POSTFIELDS must be after set CURLOPT_POST, otherwise - 400 Bad request
	        curl_setopt($curl, CURLOPT_POSTFIELDS, $postfields);
			$result = curl_exec($curl);
			curl_setopt($curl, CURLOPT_POST, false);
			$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			if ($result === false) {
				return $this->error('cURL error: ' . curl_error($curl), curl_errno($curl));
			}
			// if posted successfully, result contains $text_head
			$result = $this->convertEncoding($result, true);
			if (mb_strpos(stripslashes($result), $text_head) === false) {
				// if text not found, but we have HTTP 200 OK, maybe we are not logged in?
				if ($http_code != 200) {
					return $this->error('Post failed. Response HTTP code ' . $http_code, 9);
				}
				if (!$this->isLoggedIn() and !$this->logIn()) {
					return $this->error('Post failed. Unrecoverable authorization lost', 10);
				}
				continue; // try one more time
			}
			return true;
		}
		return $this->error('Post failed with maximum tries', 11);
	}
    /**
     * Get posted article list for given site name
     *
     * @param string $site_name
     * @return string[]
     */
    public function getArticleList($site_name) {
        $curl = $this->getCurlHandler();
        $postfields = array(
            'action' => 'getReport',
            'host' => $site_name,
            'mvcDataLoadSignature' => 'reportData',
            'service' => 'ORIGINALS'
        );
        curl_setopt($curl, CURLOPT_URL, 'http://webmaster.yandex.ru/site/plugins/wsw.api/api.xml');
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postfields);
        $result = curl_exec($curl);
        curl_setopt($curl, CURLOPT_POST, false);
		if ($result === false) {
			return $this->error('cURL error: ' . curl_error($curl), curl_errno($curl));
		}
        $result = $this->convertEncoding($result, true);
        $pattern = '~"Original_text","([^"]+)",[^,]+,"(.*)",[^,]+,"([^"]+)"~U';
        if (!preg_match_all($pattern, $result, $matches)) {
            return $this->error('Article list not found in response', 4);
        }
        $found = array();
        foreach ($matches[1] as $idx => $text_id) {
            $found[$text_id] = array(
                'created' => $matches[3][$idx],
                'createdts' => strtotime($matches[3][$idx]),
                'text' => $matches[2][$idx]
            );
        }
        return $found;
    }
	public function getError() {
		return $this->errmsg;
	}
	public function getErrno() {
		return $this->errcode;
	}

	private function error($errmsg, $errcode) {
		$this->errmsg = $errmsg;
		$this->errcode = $errcode;
		return false;
	}
	/**
     * Convert text to remote server encoding to make request properly
     * Convert response text to own encoding (with reverse == true)
     *
     * @param string $text
     * @param boolean $reverse
     * @return string
     */
    private function convertEncoding($text, $reverse = false) {
        $encodings = array(
            ($reverse ? 'out' : 'in') => $this->getInputEncoding($text),
            ($reverse ? 'in' : 'out') => $this->getOutputEncoding()
        );
        return ($encodings['in'] == $encodings['out'] ? $text : iconv($encodings['in'], $encodings['out']."//TRANSLIT", $text));
    }
    /**
     *
     * @return string
     */
    private function getInputEncoding($text = '') {
		if (!$this->input_encoding) { // if not set, try to detect using input text
			$this->input_encoding = $this->detectEncoding($text);
		}
        return strtolower($this->input_encoding);
    }
	public function detectEncoding($text) {
		if (!preg_match('//u', $text)) {
			return 'cp1251';
		}
		return 'utf-8';
	}
    /**
     *
     * @return string
     */
    private function getOutputEncoding() {
        return strtolower(self::OUTPUT_ENCODING);
    }
    /**
     *
     * @return string
     */
    private function getLoginUrl() {
        return $this->login_url;
    }
    /**
     *
     * @return string
     */
    private function getBaseUrl() {
        return $this->base_url;
    }
    /**
     *
     * @return string
     */
    private function getCurlCookieFilename() {
        return $this->cookie_filename;
    }
    /**
     *
     * @return string
     */
    private function getLogin() {
        return $this->login;
    }
    /**
     *
     * @return string
     */
    private function getPasswd() {
        return $this->passwd;
    }
    /**
     *
     * @return resource
     */
    private function getCurlHandler() {
        if (!$this->curl_handler) {
            $this->curl_handler = curl_init();
            curl_setopt($this->curl_handler, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl_handler, CURLOPT_FOLLOWLOCATION, true);
            if ($cookie_filename = $this->getCurlCookieFilename()) {
                curl_setopt($this->curl_handler, CURLOPT_COOKIEFILE, $cookie_filename);
                curl_setopt($this->curl_handler, CURLOPT_COOKIEJAR, $cookie_filename);
            }
        }
        return $this->curl_handler;
    }
}

