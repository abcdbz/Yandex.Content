<?php

	/**
	 * Yandex.Webmaster class for post articles
	 *
	 * How to use:
	 * make instance: $yc = new YandexContent('mylogin', 'mypass');
	 * set your encoding: $yc->setInputEncoding('cp1251');
	 * ..and post: $result = $yc->postArticle($article, 'www.mysite.ru');
	 */

	class YandexContent
	{

		const OUTPUT_ENCODING = 'UTF-8';
		const TEXT_LENGTH_MIN = 2000;
		const TEXT_LENGTH_MAX = 32000;

		const ERR_LOGIN_FAILED              = 1;
		const ERR_ARTICLE_LIST_NOT_FOUND    = 2;
		const ERR_POST_FAILED_WITH_MAXTRIES = 3;
		const ERR_AUTORELOGIN_FAILED        = 4;
		const ERR_BAD_RESPONSE_CODE         = 5;
		const ERR_WRONG_TEXT_SIZE           = 6;
		const ERR_SITE_NAME_NOT_ALLOWED     = 7;
		const ERR_SITE_LIST_NOT_FOUND       = 8;
		const ERR_NOT_LOGGED_IN             = 9;

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
		 * @param string  $login
		 * @param string  $pass
		 * @param boolean $use_pconn Whether or not to save cookie file for permanent curl session
		 */
		public function __construct( $login, $pass, $use_pconn = true )
		{
			$this->login           = $login;
			$this->passwd          = $pass;
			$tmpdir                = sys_get_temp_dir() . '/';
			$this->cookie_filename = ( $use_pconn ? $tmpdir . md5( $login . $pass ) : tempnam( $tmpdir, '' ) );
			if ( !touch( $this->cookie_filename ) or !is_writable( $this->cookie_filename ) )
			{
				return $this->error( 'Cant write to curl cookie file [' . $this->cookie_filename . ']', 1 );
			}
			if ( !$this->isLoggedIn() and !$this->logIn() )
			{
				return $this->error( 'Failed to log in to yandex.webmaster', self::ERR_LOGIN_FAILED );
			}
			// кто то спрашивал, почему я люблю exceptions и не люблю $this->error
			// смотрим строки выше: $this->isLoggedIn() первый раз ставит $this->error = 'фиг'
			// затем идет $this->logIn(), где все проходит нормально, но как это понять?
			// ведь ошибка все еще в $this->error, кто ее должен стирать? "а хз.. давайте я".
			// вот тут и стираем, а то в крон скрипте после создания объекта вечная ошибка ERR_LOGIN_FAILED
			// upd: при рефакторинге $this->errcode = $this->errmsg = null заменено на return $this->noError()
			return $this->noError();
		}

		public function __destruct()
		{
			if ( $this->curl_handler )
			{
				curl_close( $this->curl_handler );
			}
		}

		/**
		 * Set encoding, which you use in your system
		 *
		 * @param string $encoding
		 */
		public function setInputEncoding( $encoding )
		{
			$this->input_encoding = $encoding;
		}

		/**
		 *
		 * @param string $cookie_filename Real path to cookie filename with write access
		 */
		public function setCurlCookieFilename( $cookie_filename )
		{
			$this->cookie_filename = $cookie_filename;
		}

		/**
		 * post credentials to yandex login page
		 * call it before first use or if is_logged_in() == false
		 *
		 * @return boolean Logged in or not
		 */
		public function logIn()
		{
			$postfields = 'login=' . $this->getLogin() . '&passwd=' . $this->getPasswd();
			$curl       = $this->getCurlHandler();

			curl_setopt( $curl, CURLOPT_POST, true );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $postfields );
			curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 2 );
			curl_setopt( $curl, CURLOPT_URL, $this->getLoginUrl() );
			$result = curl_exec( $curl );
			curl_setopt( $curl, CURLOPT_POST, false );
			if ( $result === false )
			{
				return $this->error( 'cURL error: ' . curl_error( $curl ), curl_errno( $curl ) );
			}
			if ( !$this->isLoggedIn() )
			{
				return $this->error( 'Login failed', self::ERR_LOGIN_FAILED );
			}
			return true;
		}

		/**
		 * Check, if already logged in
		 *
		 * @return boolean
		 */
		public function isLoggedIn()
		{
			$curl = $this->getCurlHandler();
			curl_setopt( $curl, CURLOPT_URL, $this->getBaseUrl() );
			$result = curl_exec( $curl );
			if ( $result === false )
			{
				return $this->error( 'cURL error: ' . curl_error( $curl ), curl_errno( $curl ) );
			}
			if ( mb_strpos( $result, 'http://passport.yandex.ru/passport?mode=logout' ) === false )
			{
				return $this->error( 'Not logged in, logout button not found', self::ERR_NOT_LOGGED_IN );
			}
			return true;
		}

		/**
		 *
		 * @param boolean $only_approved
		 *
		 * @return string[]
		 */
		public function getSiteList( $only_approved = true )
		{
			$curl = $this->getCurlHandler();
			curl_setopt( $curl, CURLOPT_URL, $this->getBaseUrl() );
			$page = curl_exec( $curl );

			// :TODO: WTF ???
			/*
			if ( $result === false )
			{
				return $this->error( 'cURL error: ' . curl_error( $curl ), curl_errno( $curl ) );
			}
			*/

			$pattern = '~mvc\.map\(.*,\[(".*)\]~Ui';
			if ( !preg_match_all( $pattern, $page, $matches ) )
			{
				return $this->error( 'Site list not found in response', self::ERR_SITE_LIST_NOT_FOUND );
			}
			$found = array();
			foreach ( $matches[1] as $idx => $string )
			{
				$tmp = explode( ',', $string );
				// if data broken, skip
				if ( !isset( $tmp[1] ) or !isset( $tmp[2] ) or !isset( $tmp[3] ) )
				{
					continue;
				}
				$site_id     = $tmp[1];
				$site_name   = $tmp[2];
				$is_approved = ( $tmp[3] == 'true' );
				if ( $is_approved or !$only_approved )
				{
					$found[$site_id] = $site_name;
				}
			}
			return $found;
		}

		/**
		 * Post article and link it to site name
		 *
		 * @param string $text
		 * @param string $site_name
		 *
		 * @return boolean
		 */
		public function postArticle( $text, $site_name )
		{
			if ( empty( $site_name ) )
			{
				return $this->error( 'Empty site name not allowed', self::ERR_SITE_NAME_NOT_ALLOWED );
			}
			$text = strip_tags( html_entity_decode( $text, ENT_QUOTES, $this->getInputEncoding( $text ) ) );
			if ( ( $len = mb_strlen( $text ) ) < self::TEXT_LENGTH_MIN )
			{
				return $this->error( 'Wrong text size (' . $len . '). Must be between ' . self::TEXT_LENGTH_MIN . ' and ' . self::TEXT_LENGTH_MAX, self::ERR_WRONG_TEXT_SIZE );
			}
			if ( $len > self::TEXT_LENGTH_MAX )
			{
				$delimiter = '#$#$#$#';
				// разбиваем, да так, чтоб хвост был длиннее self::TEXT_LENGTH_MIN
				$wrapped = explode( $delimiter, wordwrap( $text, self::TEXT_LENGTH_MAX - self::TEXT_LENGTH_MIN, $delimiter ) );
				$result  = true;
				foreach ( $wrapped as $subtext )
				{
					$result = $result && $this->postArticle( $subtext, $site_name );
				}
				return $result;
			}
			$text_head  = mb_substr( $text, 0, 100 ); // for post check
			$text       = $this->convertEncoding( $text );
			$postfields = array(
				'action'     => 'saveData',
				'host'       => $site_name,
				#'mvcDataLoadSignature' => 'saveData',
				'page'       => 'Originals-submission-form',
				'service'    => 'ORIGINALS',
				'wsw-fields' => '<wsw-fields>' . '<wsw-field name="host"><wsw-value>' . $site_name . '</wsw-value></wsw-field>' . '<wsw-field name="Original_text"><wsw-value>' . htmlspecialchars( $text ) . '</wsw-value></wsw-field>' . '</wsw-fields>'
			);
			$curl       = $this->getCurlHandler();
			curl_setopt( $curl, CURLOPT_URL, 'http://webmaster.yandex.ru/site/plugins/wsw.api/api.xml' );
			curl_setopt( $curl, CURLOPT_HEADER, 1 );
			$i = 3; // tries counter
			while ( $i-- )
			{
				curl_setopt( $curl, CURLOPT_POST, true );
				// CURLOPT_POSTFIELDS must be after set CURLOPT_POST, otherwise - 400 Bad request
				curl_setopt( $curl, CURLOPT_POSTFIELDS, $postfields );
				$result = curl_exec( $curl );
				curl_setopt( $curl, CURLOPT_POST, false );
				$http_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
				if ( $result === false )
				{
					return $this->error( 'cURL error: ' . curl_error( $curl ), curl_errno( $curl ) );
				}
				// if posted successfully, result contains $text_head
				$result = $this->convertEncoding( $result, true );
				// обработка ответа, заменим строки "\n" на настоящий LF и декодируем html обратно
				$result = str_replace( array( '\n', '\t' ), array( "\n", "\t" ), htmlspecialchars_decode( $result ) );
				if ( mb_strpos( stripslashes( $result ), $text_head ) === false )
				{
					// if text not found, but we have HTTP 200 OK, maybe we are not logged in?
					if ( $http_code != 200 )
					{
						return $this->error( 'Post failed. Response HTTP code ' . $http_code, self::ERR_BAD_RESPONSE_CODE );
					}
					if ( !$this->isLoggedIn() and !$this->logIn() )
					{
						return $this->error( 'Post failed. Unrecoverable authorization lost', self::ERR_AUTORELOGIN_FAILED );
					}
					continue; // try one more time
				}
				// а вот тут уже опасно! читали коммент в конструкторе? продолжаем!
				// случился у нас, например, ERR_WRONG_TEXT_SIZE, ошибку записали, след.текст обрабатываем
				// все хорошо, но ошибка-то сохранена и проверяя ее снаружи мы можем сделать
				// неверный вывод, что и тут косяк произошел! и, руководствуясь им, например
				// удалить задание из очереди почем зря.. что делать? конечно надо почистить ошибки!
				// тут уже рефакторинг придется делать, return true заменим на return $this->noError()
				// во как!
				return $this->noError();
			}
			return $this->error( 'Post failed with maximum tries', self::ERR_POST_FAILED_WITH_MAXTRIES );
		}


		/**
		 * Get posted article list for given site name
		 *
		 * @param string $site_name
		 *
		 * @return string[]
		 */
		public function getArticleList( $site_name )
		{
			$curl       = $this->getCurlHandler();
			$postfields = array(
				'action'               => 'getReport',
				'host'                 => $site_name,
				'mvcDataLoadSignature' => 'reportData',
				'service'              => 'ORIGINALS'
			);
			curl_setopt( $curl, CURLOPT_URL, 'http://webmaster.yandex.ru/site/plugins/wsw.api/api.xml' );
			curl_setopt( $curl, CURLOPT_POST, true );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $postfields );
			$result = curl_exec( $curl );
			curl_setopt( $curl, CURLOPT_POST, false );
			if ( $result === false )
			{
				return $this->error( 'cURL error: ' . curl_error( $curl ), curl_errno( $curl ) );
			}
			$result  = $this->convertEncoding( $result, true );
			$pattern = '~"Original_text","([^"]+)",[^,]+,"(.*)",[^,]+,"([^"]+)"~U';
			if ( !preg_match_all( $pattern, $result, $matches ) )
			{
				return $this->error( 'Article list not found in response', self::ERR_ARTICLE_LIST_NOT_FOUND );
			}
			$found = array();
			foreach ( $matches[1] as $idx => $text_id )
			{
				$found[$text_id] = array(
					'created'   => $matches[3][$idx],
					'createdts' => strtotime( $matches[3][$idx] ),
					'text'      => $matches[2][$idx]
				);
			}
			return $found;
		}

		public function getError()
		{
			return $this->errmsg;
		}

		public function getErrno()
		{
			return $this->errcode;
		}

		private function noError()
		{
			$this->errcode = $this->errmsg = null;
			return true;
		}

		private function error( $errmsg, $errcode )
		{
			$this->errmsg  = $errmsg;
			$this->errcode = $errcode;
			return false;
		}

		/**
		 * Convert text to remote server encoding to make request properly
		 * Convert response text to own encoding (with reverse == true)
		 *
		 * @param string  $text
		 * @param boolean $reverse
		 *
		 * @return string
		 */
		private function convertEncoding( $text, $reverse = false )
		{
			$encodings = array(
				( $reverse ? 'out' : 'in' ) => $this->getInputEncoding( $text ),
				( $reverse ? 'in' : 'out' ) => $this->getOutputEncoding()
			);
			return ( $encodings['in'] == $encodings['out'] ? $text : iconv( $encodings['in'], $encodings['out'], $text ) );
		}

		/**
		 * @param   string
		 * @return  string
		 */
		private function getInputEncoding( $text = '' )
		{
			if ( !$this->input_encoding )
			{ // if not set, try to detect using input text
				$this->input_encoding = $this->detectEncoding( $text );
			}
			return strtolower( $this->input_encoding );
		}

		private function detectEncoding( $text )
		{
			if ( !preg_match( '//u', $text ) )
			{
				return 'cp1251';
			}
			return 'utf-8';
		}

		/**
		 *
		 * @return string
		 */
		private function getOutputEncoding()
		{
			return strtolower( self::OUTPUT_ENCODING );
		}

		/**
		 *
		 * @return string
		 */
		private function getLoginUrl()
		{
			return $this->login_url;
		}

		/**
		 *
		 * @return string
		 */
		private function getBaseUrl()
		{
			return $this->base_url;
		}

		/**
		 *
		 * @return string
		 */
		private function getCurlCookieFilename()
		{
			return $this->cookie_filename;
		}

		/**
		 *
		 * @return string
		 */
		private function getLogin()
		{
			return $this->login;
		}

		/**
		 *
		 * @return string
		 */
		private function getPasswd()
		{
			return $this->passwd;
		}

		/**
		 *
		 * @return resource
		 */
		private function getCurlHandler()
		{
			if ( !$this->curl_handler )
			{
				$this->curl_handler = curl_init();
				curl_setopt( $this->curl_handler, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $this->curl_handler, CURLOPT_FOLLOWLOCATION, true );
				// force HTTP/1.0, otherwise yandex make HTTP 417 response on request
				// with header "Expect: 100-continue"
				curl_setopt( $this->curl_handler, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0 );
				if ( $cookie_filename = $this->getCurlCookieFilename() )
				{
					curl_setopt( $this->curl_handler, CURLOPT_COOKIEFILE, $cookie_filename );
					curl_setopt( $this->curl_handler, CURLOPT_COOKIEJAR, $cookie_filename );
				}
			}
			return $this->curl_handler;
		}
	}

