<?php
/*
Plugin Name: Pareto Security
Plugin URI: http://hokioisec7agisc4.onion/?p=25
Description: Core Security Class - Defense against a range of common attacks such as database injection
Author: Te_Taipo
Version: 1.1.7
Author URI: http://hokioisec7agisc4.onion
BTC:1LHiMXedmtyq4wcYLedk9i9gkk8A8Hk7qX
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

See: See http://www.gnu.org/licenses/gpl-3.0.txt

*/

if ( defined( 'WP_PLUGIN_DIR' ) ) {
    // don't load directly
    if ( !function_exists( 'is_admin' ) ) {
        header( 'Status: 403 Forbidden' );
        header( 'HTTP/1.1 403 Forbidden' );
        exit();
    }
    # Set Pareto Security as the first plugin loaded
    add_action( "activated_plugin", "load_pareto_first" );
    
    define( 'PARETO_VERSION', '1.1.7' );
    define( 'PARETO_RELEASE_DATE', date_i18n( 'F j, Y', '1460759778' ) );
    define( 'PARETO_DIR', plugin_dir_path( __FILE__ ) );
    define( 'PARETO_URL', plugin_dir_url( __FILE__ ) );
}

class ParetoSecurity {
    # protect from non-standard request types
    protected $_nonGETPOSTReqs = 0;
    # if open_basedir is not set in php.ini. Leave disabled unless you are sure about using this.
    public $_open_basedir = 0;
    # ban attack ip address to the root /.htaccess file. Leave this disabled if you are hosting a website using TOR's Hidden Services
    public $_banip = 0;
    # path to the root directory of the site, e.g /home/user/public_html
    public $_doc_root = '';
    # Correct Production Server Settings = 0, prevent any errors from displaying = 1, Show all errors = 2 ( depends on the php.ini settings )
    protected $_quietscript = 0;
    
    # Other
    protected $_bypassbanip = false;
    var $settings, $options_page;
    
    public function __construct() {
        
        if ( defined( 'WP_PLUGIN_DIR' ) ) {
            require( PARETO_DIR . 'pareto-settings.php' );
            $ParetoSettings = new Pareto_Security_Settings();
            
            register_activation_hook( __FILE__, array(
                 $this,
                'activate' 
            ) );
            register_deactivation_hook( __FILE__, array(
                 $this,
                'deactivate' 
            ) );
            $this->_banip = isset( $ParetoSettings->ban_ips ) ? $ParetoSettings->ban_ips : $this->_banip;
            $this->_nonGETPOSTReqs = isset( $ParetoSettings->reqmethod ) ? $ParetoSettings->reqmethod : $this->_nonGETPOSTReqs;
        }
        $this->_set_error_level();
        $this->_bypassbanip = false;
        # if open_basedir is not set in php.ini then set it in the local scope
        $this->setOpenBaseDir();
        # Send secure headers
        $this->x_secure_headers();
        # Shields Up
        $this->_REQUEST_SHIELD();
        $this->_QUERYSTRING_SHIELD();
        $this->_POST_SHIELD();
        $this->_COOKIE_SHIELD();
        $this->_REQUESTTYPE_SHIELD();
        $this->_SPIDER_SHIELD();
        # Merge $_REQUEST with _GET and _POST excluding _COOKIE data
        # php.ini may already do this
        $_REQUEST = array_merge( $_GET, $_POST );
    } // end of __construct()
    
    function network_propagate( $pfunction, $networkwide ) {
        global $wpdb;
        
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            // check if it is a network activation - if so, run the activation function 
            // for each blog id
            if ( $networkwide ) {
                $old_blog = $wpdb->blogid;
                // Get all blog ids
                $blogids  = array();
                $blogids  = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
                foreach ( $blogids as $blog_id ) {
                    switch_to_blog( $blog_id );
                    call_user_func( $pfunction, $networkwide );
                }
                switch_to_blog( $old_blog );
                return;
            }
        }
        call_user_func( $pfunction, $networkwide );
    }
    
    function activate( $networkwide ) {
        $this->network_propagate( array(
             $this,
            '_activate' 
        ), $networkwide );
    }
    
    function deactivate( $networkwide ) {
        $this->network_propagate( array(
             $this,
            '_deactivate' 
        ), $networkwide );
    }
    
    function _activate() {
    }
    function _deactivate() {
    }
    
    function _set_error_level() {
        $val = ( false !== $this->integ_prop( $this->_quietscript ) || false !== ctype_digit( $this->_quietscript ) ) ? ( int ) $this->_quietscript : 0;
        switch ( ( int ) $val ) {
            case ( 0 ):
                error_reporting( 6135 );
                break;
            case ( 1 ):
                error_reporting( 0 );
                ini_set( 'display_errors', 0 );
                break;
            case ( 2 ):
                error_reporting( 32767 );
                ini_set( 'display_errors', 1 );
                break;
            default:
                error_reporting( 6135 );
        }
    }
    /**
     * send403()
     * 
     * @return
     */
    function send403() {
        $header = array(
             'HTTP/1.1 403 Access Denied',
             'Status: 403 Access Denied by Hokioi-Security :: Pareto Security',
             'Content-Length: 0' 
        );
        foreach ( $header as $sent ) {
            header( $sent );
        }
        die();
    }
    
    /**
     * karo()
     * 
     * @return
     */
    function karo( $t = false ) {
        if ( false === ( bool ) $this->_banip )
            $this->send403();
        # set strict conditions for htaccess banning an IP address
        # - htaccess exists and is writeable
        # - request is for an IP ban
        # - no bypass request triggered
        # - script manually set to ban IPs to htaccess
        if ( ( false !== $this->hCoreFileChk( '.htaccess', TRUE, TRUE ) ) && ( false !== ( bool ) $t ) && ( false === ( bool ) $this->_bypassbanip ) && ( false !== ( bool ) $this->_banip ) ) {
            $this->htaccessbanip( ( strlen( $this->getRealIP() ) < 7 ) ? $_SERVER[ 'REMOTE_ADDR' ] : $this->getRealIP() );
        }
        # end with soft ban
        $this->send403();
    }
    /**
     * injectMatch()
     * 
     * @param mixed $string
     * @return
     */
    function injectMatch( $string ) {
        $string  = $this->url_decoder( strtolower( $string ) );
        $kickoff = false;
        # these are the triggers to engage the rest of this function.
        $vartrig = "\/\/|\.\.\/|%0d%0a|0x|a(?:ll|scii\()|b(?:ase64|enchmark|y)|c(?:har|
                   olumn|onvert|ookie|reate)|d(?:eclare|ata|ate|elete|rop)|concat|
                   e(?:val|xec)|f(?:rom|tp)|g(?:rant|roup)|i(?:nsert|snull|nto)|js|
                   l(?:ength\(|oad)|master|onmouse|null|php|s(?:chema|elect|et|hell|
                   how|leep)|table|u(?:nion|pdate|tf)|var|w(?:aitfor|here|hile)";
        $vartrig = preg_replace( "/[\s]/i", "", $vartrig );
        for ( $x = 1; $x <= 5; $x++ ) {
            $string = $this->cleanString( $x, $string );
            if ( false !== ( bool ) preg_match( "/$vartrig/i", $string ) ) {
                $kickoff = true;
                break;
            }
        }
        if ( false === $kickoff ) {
            return false; // if false then we are not interested in this query.
        } else { // else we are very interested in this query.
            $j                = 1;
            # toggle through 6 different filters
            $sqlmatchlist     = "(?:abs|ascii|base64|bin|cast|chr|char|charset|
							collation|concat|conv|convert|count|curdate|database|date|
							decode|diff|distinct|else|elt|end||encode|encrypt|extract|field|
							_file|floor|format|hex|if|inner|insert|instr|interval|join|lcase|
							left|length|like|load_file|locate|lock|log|lower|lpad|ltrim|max|
							md5|mid|mod|name|now|null|ord|password|position|quote|rand|
							repeat|replace|reverse|right|rlike|round|row_count|rpad|rtrim|
							_set|schema|select|sha1|sha2|serverproperty|soundex|
							space|strcmp|substr|substr_index|substring|sum|time|trim|
							truncate|ucase|unhex|upper|_user|user|values|varchar|
							version|while|ws|xor)\(|\(0x|@@|cast|integer";
            $sqlupdatelist    = "\bcolumn\b|\bdata\b|concat\(|\bemail\b|\blogin\b|
						    \bname\b|\bpass\b|sha1|sha2|\btable\b|table|\bwhere\b|\buser\b|
						    \bval\b|0x|--";
            $sqlfilematchlist = 'access_|access.|\balias\b|apache|\/bin|win.|
							\bboot\b|config|\benviron\b|error_|error.|\/etc|httpd|
							_log|\.(?:js|txt|exe|ht|ini|bat|log)|\blib\b|\bproc\b|
							\bsql\b|tmp|tmp\/sess|\busr\b|\bvar\b|\/(?:uploa|passw)d';
            $sqlmatchlist2    = '@@|_and|ascii|b(?:enchmark|etween|in|itlength|
							ulk)|c(?:ast|har|ookie|ollate|olumn|oncat|urrent)|\bdate\b|
							dump|e(?:lt|xport)|false|\bfield\b|fetch|format|function|
							\bhaving\b|i(?:dentity|nforma|nstr)|\bif\b|\bin\b|inner|insert|
							l(?:case|eft|ength|ike|imit|oad|ocate|ower|pad|trim)|join|
							m(:?ade by|ake|atch|d5|id)|not_like|not_regexp|null|\bon\b|
							order|outfile|p(?:ass|ost|osition|riv)|\bquote\b|\br(?:egexp\b|
							ename\b|epeat\b|eplace\b|equest\b|everse\b|eturn\b|ight\b|
							like\b|pad\b|trim\b)|\bs(?:ql\b|hell\b|leep\b|trcmp\b|ubstr\b)|
							\bt(?:able\b|rim\b|rue\b|runcate\b)|u(?:case|nhex|pdate|
							pper|ser)|values|varchar|\bwhen\b|where|with|\(0x|
							_(?:decrypt|encrypt|get|post|server|cookie|global|or|
							request|xor)|(?:column|db|load|not|octet|sql|table|xp)_|
							version|auto_prepend_file|allow_url_include';
            while ( $j <= 6 ) {
                $string = $this->cleanString( $j, $string );
                
                $sqlmatchlist     = preg_replace( "/[\s]/i", '', $sqlmatchlist );
                $sqlupdatelist    = preg_replace( "/[\s]/i", '', $sqlupdatelist );
                $sqlfilematchlist = preg_replace( "/[\s]/i", '', $sqlfilematchlist );
                $sqlmatchlist2    = preg_replace( "/[\s]/i", '', $sqlmatchlist2 );
                
                if ( false !== ( bool ) preg_match( "/\bdrop\b/i", $string ) && false !== ( bool ) preg_match( "/\btable\b|\buser\b/i", $string ) && false !== ( bool ) preg_match( "/--|and||\//i", $string ) ) {
                    return true;
                } elseif ( ( false !== strpos( $string, 'grant' ) ) && ( false !== strpos( $string, 'all' ) ) && ( false !== strpos( $string, 'privileges' ) ) ) {
                    return true;
                } elseif ( false !== ( bool ) preg_match( "/(?:(sleep\((\s*)(\d*)(\s*)\)|benchmark\((.*)\,(.*)\)))/i", $string ) ) {
                    return true;
                } elseif ( false !== preg_match_all( "/\bload\b|\bdata\b|\binfile\b|\btable\b|\bterminated\b/i", $string, $matches ) > 3 ) {
                    return true;
                } elseif ( ( ( false !== ( bool ) preg_match( "/select|sleep|isnull|declare|ascii\(substring|length\(/i", $string ) ) && ( false !== ( bool ) preg_match( "/\band\b|\bif\b|group_|_ws|load_|exec|when|then|concat\(|\bfrom\b/i", $string ) ) && ( false !== ( bool ) preg_match( "/$sqlmatchlist/i", $string ) ) ) ) {
                    return true;
                } elseif ( false !== preg_match_all( "/$sqlmatchlist/i", $string, $matches ) > 2 ) {
                    return true;
                } elseif ( false !== strpos( $string, 'update' ) && false !== ( bool ) preg_match( "/\bset\b/i", $string ) && false !== ( bool ) preg_match( "/$sqlupdatelist/i", $string ) ) {
                    return true;
                } elseif ( false !== strpos( $string, 'having' ) && false !== ( bool ) preg_match( "/\bor\b|\band\b/i", $string ) && false !== ( bool ) preg_match( "/$sqlupdatelist/i", $string ) ) {
                    return true;
                    # tackle the noDB / js issue
                } elseif ( ( substr_count( $string, 'var' ) > 1 ) && false !== ( bool ) preg_match( "/date\(|while\(|sleep\(/i", $string ) ) {
                    return true;
                    # reflected download attack
                } elseif ( ( substr_count( $string, '|' ) > 2 ) && false !== ( bool ) preg_match( "/json/i", $string ) ) {
                    return true;
                }
                # run through a set of filters to find specific attack vectors
                $thenode = $this->cleanString( $j, $this->getREQUEST_URI() );
                
                if ( ( false !== ( bool ) preg_match( "/onmouse(?:down|over)/i", $string ) ) && ( 2 < ( int ) preg_match_all( "/c(?:path|tthis|t\(this)|http:|(?:forgotte|admi)n|sqlpatch|,,|ftp:|(?:aler|promp)t/i", $thenode, $matches ) ) ) {
                    return true;
                } elseif ( ( ( false !== strpos( $thenode, 'ftp:' ) ) && ( substr_count( $thenode, 'ftp' ) > 1 ) ) && ( 2 < ( int ) preg_match_all( "/@|\/\/|:/i", $thenode, $matches ) ) ) {
                    return true;
                } elseif ( ( 'POST' == $_SERVER[ 'REQUEST_METHOD' ] ) && ( false !== ( bool ) preg_match( "/(?:showimg|cookie|cookies)=/i", $string ) ) ) {
                    return true;
                } elseif ( ( substr_count( $string, '../' ) > 3 ) || ( substr_count( $string, '..//' ) > 3 ) || ( substr_count( $string, '0x2e0x2e/' ) > 1 ) ) {
                    if ( false !== ( bool ) preg_match( "/$sqlfilematchlist/i", $string ) ) {
                        return true;
                    }
                } elseif ( ( substr_count( $string, '/' ) > 1 ) && ( 2 <= ( int ) preg_match_all( "/$sqlfilematchlist/i", $thenode, $matches ) ) ) {
                    return true;
                } elseif ( ( false !== ( bool ) preg_match( "/%0D%0A/i", $thenode ) ) && ( false !== strpos( $thenode, 'utf-7' ) ) ) {
                    return true;
                } elseif ( false !== ( bool ) preg_match( "/php:\/\/filter|convert.base64-(?:encode|decode)|zlib.(?:inflate|deflate)/i", $string ) || false !== ( bool ) preg_match( "/data:\/\/filter|text\/plain|http:\/\/(?:127.0.0.1|localhost)/i", $string ) ) {
                    return true;
                }
                
                if ( 5 <= substr_count( $string, '%' ) )
                    $string = str_replace( '%', '', $string );
                
                if ( ( false !== ( bool ) preg_match( "/\border by\b|\bgroup by\b/i", $string ) ) && ( false !== ( bool ) preg_match( "/\bcolumn\b|\bdesc\b|\berror\b|\bfrom\b|hav|\blimit\b|offset|\btable\b|\/|--/i", $string ) || ( false !== ( bool ) preg_match( "/\b[0-9]\b/i", $string ) ) ) ) {
                    return true;
                } elseif ( ( false !== ( bool ) preg_match( "/\btable\b|\bcolumn\b/i", $string ) ) && false !== strpos( $string, 'exists' ) && false !== ( bool ) preg_match( "/\bif\b|\berror\b|\buser\b|\bno\b/i", $string ) ) {
                    return true;
                } elseif ( ( false !== strpos( $string, 'waitfor' ) && false !== strpos( $string, 'delay' ) && ( ( bool ) preg_match( "/(:)/i", $string ) ) ) || ( false !== strpos( $string, 'nowait' ) && false !== strpos( $string, 'with' ) && ( false !== ( bool ) preg_match( "/--|\/|\blimit\b|\bshutdown\b|\bupdate\b|\bdesc\b/i", $string ) ) ) ) {
                    return true;
                } elseif ( false !== ( bool ) preg_match( "/\binto\b/i", $string ) && ( false !== ( bool ) preg_match( "/\boutfile\b/i", $string ) ) ) {
                    return true;
                } elseif ( false !== ( bool ) preg_match( "/\bdrop\b/i", $string ) && ( false !== ( bool ) preg_match( "/\buser\b/i", $string ) ) ) {
                    return true;
                } elseif ( ( ( false !== strpos( $string, 'create' ) && false !== ( bool ) preg_match( "/\btable\b|\buser\b|\bselect\b/i", $string ) ) || ( false !== strpos( $string, 'delete' ) && false !== strpos( $string, 'from' ) ) || ( false !== strpos( $string, 'insert' ) && ( false !== ( bool ) preg_match( "/\bexec\b|\binto\b|from/i", $string ) ) ) || ( false !== strpos( $string, 'select' ) && ( false !== ( bool ) preg_match( "/\bby\b|\bcase\b|extract|from|\bif\b|\binto\b|\bord\b|union/i", $string ) ) ) ) && ( ( false !== ( bool ) preg_match( "/$sqlmatchlist2/i", $string ) ) || ( 2 <= substr_count( $string, ',' ) ) ) ) {
                    return true;
                } elseif ( ( false !== strpos( $string, 'union' ) ) && ( false !== strpos( $string, 'select' ) ) && ( false !== strpos( $string, 'from' ) ) ) {
                    return true;
                } elseif ( false !== strpos( $string, 'etc/passwd' ) ) {
                    return true;
                } elseif ( false !== strpos( $string, 'null' ) ) {
                    $nstring = preg_replace( "/[^a-z]/i", '', $this->url_decoder( $string ) );
                    if ( false !== ( bool ) preg_match( "/(null){3,}/i", $nstring ) ) {
                        return true;
                    }
                }
                $j++;
            }
        }
        return false;
    }
    /**
     * blacklistMatch()
     * 
     * @return
     */
    private static function blacklistMatch( $val, $list = 0 ) {
        # although we try not to do this, arbitrary blacklisting of certain request variables
        # cannot be avoided. however I will attempt to keep this list short.
        
        # $list should never have a value of 0
        if ( $list == 0 )
            die( 'there is an error' );
        $_blacklist      = array();
        # _GET[]
        $_blacklist[ 1 ] = "php\/login|eval\(base64\_decode|asc%3Deval|eval\(\\$\_|@eval|EXTRACTVALUE\(|
          allow\_url\_include|safe\_mode|suhosin\.simulation|disable\_functions|phpinfo\(|
          open\_basedir|auto\_prepend\_file|php:\/\/input|\)limit|rush=|fromCharCode|\}catch\(e|
          ;base64|base64,|prompt\(|onerror=alert\(|javascript:prompt\(|\/var\/lib\/php|
          javascript:alert\(|pwtoken\_get|php\_uname|%3Cform|passthru\(|sha1\(|sha2\(|\}if\(!|
          <\?php|\/iframe|; GET|\\$\_GET|@@version|ob\_starting|and1=1|\.\.\/cmd|document\.cookie|
          document\.write|onload\=|mysql\_query|document\.location|window\.location|\]\);\}|
          location\.replace\(|\(\)\}|@@datadir|\/FRAMESET|0x3c62723e|\$HTTP\_|ping -c|ping -i|
          \[link=http:\/\/|\[\/link\]|YWxlcnQo|\_START\_|onunload%3d|PHP\_SELF|shell\_exec|
          \\$\_SERVER|;!--=|substr\(|\\$\_POST|\\$\_SESSION|\\$\_REQUEST|\\$\_ENV|GLOBALS\[|
          \.php\/admin|mosConfig\_|%3C@replace\(|hex\_ent|inurl:|replace\(|\/iframe>|return%20clk|
          php\/password\_for|unhex\(|error\_reporting\(|HTTP\_CMD|=alert\(|localhost|127.0.0.1:|
          }\)%3B|Set-Cookie|%bf%5c%27|%ef%bb%bf|%20regexp%20|\{\\$\{|%27|HTTP\/1\.|\{\\$\_|
          PRINT@@variable|xp\_cmdshell|xp\_availablemedia|sp\_password|\/var\/www\/php|
          \\$\_SESSION\[!|file\_get\_contents\(|\*\(\|\(objectclass=|\|\||\.\.\/wp-|\.htaccess|
          \.passwd|\.htpasswd|; echo|;echo|system\(\%24|UTL\_HTTP\.REQUEST|script>";
        # _POST[]
        $_blacklist[ 2 ] = "ZXZhbCg=|eval\(base64\_decode|fromCharCode|allow\_url\_include|@eval|
          php:\/\/input|concat\(@@|suhosin\.simulation=|\#\!\/usr\/bin\/perl -I|shell\_exec\(|
          file\_get\_contents\(|prompt\(|script>alert\(|fopen\(|\_GET\['cmd|\"><script|\"><javas|
          YWxlcnQo|ZnJvbUNoYXJDb2Rl";
        # 'User-Agent'
        $_blacklist[ 3 ] = "\/usr\/bin\/perl|:;\};|system\(|curl|python|base64\_|phpinfo|wget|eval\(|
					   getconfig\(|\.chr\(|passthru|shell\_exec|popen\(|exec\(";
        # _COOKIE[]
        $_blacklist[ 4 ] = "eval\(|fromCharCode|\/usr\/bin\/perl|prompt\(|ZXZhbCg=|ZnJvbUNoYXJDb2Rl|fsockopen|
					   U0VMRUNULyoqLw==|:;\};|wget http|system\(|Ki9XSEVSRS8q|YWxlcnQo|4294967296";
        
        $_thelist = preg_replace( "/[\s]/i", '', $_blacklist[ ( int ) $list ] );
        if ( false !== ( bool ) preg_match( "/$_thelist/i", $val ) ) {
            return true;
        }
        return false;
    }
    /**
     * _REQUEST_Shield()
     * 
     * @return
     */
    function _REQUEST_SHIELD() {
        # regardless of _GET or _POST
        # attacks that do not necessarily
        # involve query_string manipulation
        $req    = $this->url_decoder( $this->getREQUEST_URI() );
        $attack = false;
        
        # short $_SERVER[ 'SERVER_NAME' ] can indicate server hack
        # see http://bit.ly/1UeGu0W
        if ( strlen( $this->get_http_host() ) < 4 )
            $attack = true;
        
        # Reflected File Download Attack
        if ( false !== ( bool ) preg_match( "/\.(?:bat|cmd)/i", $req ) ) {
            $attack = true;
        }
        
        #osCommerce exploit
        if ( false !== strpos( $req, '.php/admin' ) ) {
            $attack = true;
        }
        
        # WP Author Discovery
        $ref = isset( $_SERVER[ 'HTTP_REFERER' ] ) ? $this->url_decoder( $_SERVER[ 'HTTP_REFERER' ] ) : NULL;
        if ( false === is_null( $ref ) ) {
            if ( false !== strpos( $req, '?author=' ) ) {
                $attack = true;
            }
            if ( false !== strpos( $ref, 'result' ) ) {
                if ( ( false !== strpos( $ref, 'chosen' ) ) && ( false !== strpos( $ref, 'nickname' ) ) ) {
                    $attack = true;
                }
            }
        }
        
        if ( false !== strpos( $req, '?' ) ) {
            $v = $this->hexoctaldecode( strtolower( substr( $req, strpos( $req, '?' ), ( strlen( $req ) - strpos( $req, '?' ) ) ) ) );
            if ( false !== strpos( $v, '-' ) && ( ( false !== strpos( $v, '?-' ) ) || ( false !== strpos( $v, '?+-' ) ) ) && ( ( false !== strpos( $v, '-s' ) ) || ( false !== strpos( $v, '-t' ) ) || ( false !== strpos( $v, '-n' ) ) || ( false !== strpos( $v, '-d' ) ) ) ) {
                $attack = true;
            }
        }
        # this occurence of these many slashes etc are always an attack attempt
        if ( substr_count( $req, '/' ) > 30 )
            $attack = true;
        if ( substr_count( $req, '\\' ) > 30 )
            $attack = true;
        if ( substr_count( $req, '|' ) > 30 )
            $attack = true;
        
        if ( false !== $attack ) {
            $this->karo( true );
            return;
        }
    }
    /**
     * get_filter()
     * 
     * @return
     */
    function get_filter( $val, $key ) {
        if ( false !== ( bool ) $this->string_prop( $val, 1 ) ) {
            $val = $this->hexoctaldecode( $val );
            if ( false !== $this->injectMatch( $val ) || false !== ( bool ) $this->blacklistMatch( $val, 1 ) ) {
                $this->karo( true );
            }
        }
        return;
    }
    /**
     * _QUERYSTRING_SHIELD()
     * 
     * @return
     */
    function _QUERYSTRING_SHIELD() {
        if ( false === ( bool ) $this->string_prop( $this->getQUERY_STRING(), 1 ) ) {
            return; // of no interest to us
        } else {
            $q     = array();
            $v     = array();
            $val   = '';
            $x     = 0;
            $qsdec = $this->getQUERY_STRING();
            $q     = explode( '&', $qsdec );
            array_walk_recursive( $q, array( $this, 'get_filter' ) );
        }
        return;
    }
    /**
     * post_filter()
     * 
     * @return
     */
    function post_filter( $val, $key ) {
        if ( false !== $this->blacklistMatch( $this->hexoctaldecode( $val ), 2 ) || false !== $this->blacklistMatch( $this->url_decoder( $val ), 2 ) ) {
            $this->karo( false ); // while some post content can be attacks, its best to 403 die().
        }
        return;
    }
    
    /**
     * _POST_SHIELD()
     * 
     * @return
     */
    function _POST_SHIELD() {
        if ( 'POST' !== $_SERVER[ 'REQUEST_METHOD' ] ) return; // of no interest to us
        array_walk_recursive( $_POST, array( $this, 'post_filter' ) );
    }
    /**
     * cookie_filter()
     * 
     * @return
     */
    function cookie_filter( $val, $key ) {
        if ( false !== ( bool ) $this->blacklistMatch( $key, 4 ) || false !== ( bool ) $this->blacklistMatch( $this->hexoctaldecode( $val ), 4 ) ) {
            $this->karo( true );
        }
        if ( false !== ( bool ) $this->injectMatch( $key ) || false !== ( bool ) $this->injectMatch( $val ) ) {
            $this->karo( true );
        }
        return;
    }
    /**
     * _COOKIE_SHIELD()
     * 
     * @return
     */
    function _COOKIE_SHIELD() {
        if ( false !== empty( $_COOKIE ) ) return; // of no interest to us
        array_walk_recursive( $_COOKIE, array( $this, 'cookie_filter' ) );
    }
    /**
     * _REQUESTTYPE_SHIELD()
     * 
     * @return
     */
    function _REQUESTTYPE_SHIELD() {
        if ( false === ( bool ) $this->_nonGETPOSTReqs )
            return;
        $req = $_SERVER[ 'REQUEST_METHOD' ];
        $req_whitelist = array(
             'GET',
            'POST' 
        );
        # is at least a 3 char string, is uppercase, has no numbers, is not longer than 4, is in whitelist
        if ( false !== $this->string_prop( $req, 3 ) && false !== ctype_upper( $req ) && false !== ( bool ) ( strcspn( $req, '0123456789' ) === strlen( $req ) ) && ( false !== strlen( $req ) <= 4 ) && false !== in_array( $req, $req_whitelist ) ) {
            # of no interest to us
            return;
        } else
            # is of interest to us
            $this->karo( false ); // soft block
        return;
    }
    
    /**
     * Bad Spider Block / UA filter
     */
    function _SPIDER_SHIELD() {
        if ( false === empty( $_SERVER[ 'HTTP_USER_AGENT' ] ) ) {
            if ( false !== $this->blacklistMatch( strtolower( $this->hexoctaldecode( $_SERVER[ 'HTTP_USER_AGENT' ] ) ), 3 ) ) {
                $this->karo( true );
                return;
            }
        } else
            return;
    }
    function hexoctaldecode( $code ) {
        $code = ( substr_count( $code, '\\x' ) > 0 ) ? $this->url_decoder( str_replace( '\\x', '%', $code ) ) : $code;
        if ( ( substr_count( $code, '&#x' ) > 0 ) && ( substr_count( $code, ';' ) > 0 ) ) {
            $code = $this->url_decoder( str_replace( ';', '', $code ) );
            $code = $this->url_decoder( str_replace( '&#x', '%', $code ) );
        }
        return $code;
    }
    function cleanString( $b, $s ) {
        $s = strtolower( $this->url_decoder( $s ) );
        switch ( $b ) {
            case ( 1 ):
                return preg_replace( "/[^\s{}a-z0-9_?,()=@%:{}\/\.\-]/i", '', $s );
                break;
            case ( 2 ):
                return preg_replace( "/[^\s{}a-z0-9_?,=@%:{}\/\.\-]/i", '', $s );
                break;
            case ( 3 ):
                return preg_replace( "/[^\s=a-z0-9]/i", '', $s );
                break;
            case ( 4 ): // fwr_security pro
                return preg_replace( "/[^\s{}a-z0-9_\.\-]/i", "", $s );
                break;
            case ( 5 ):
                return str_replace( '//', '/', $s );
                break;
            case ( 6 ):
                return str_replace( '/**/', ' ', $s );
                break;
            default:
                return $this->url_decoder( $s );
        }
    }
    /**
     * htaccessbanip()
     * 
     * @param mixed $banip
     * @return
     */
    function htaccessbanip( $banip ) {
        # if IP is empty or too short, or .htaccess is not read/write
        if ( false !== empty( $banip ) || ( $banip < 7 ) || ( false === $this->hCoreFileChk( '.htaccess', true, true ) ) ) {
            return $this->send403();
        } else {
            $limitend = "# End of " . $this->get_http_host() . " Pareto Security Ban\n";
            $newline  = "deny from $banip\n";
            $mybans   = file( str_replace( '//', DIRECTORY_SEPARATOR, $this->getDir() . DIRECTORY_SEPARATOR . '.htaccess' ) );
            $lastline = "";
            if ( in_array( $newline, $mybans ) )
                exit();
            if ( in_array( $limitend, $mybans ) ) {
                $i = count( $mybans ) - 1;
                while ( $mybans[ $i ] != $limitend ) {
                    $lastline = array_pop( $mybans ) . $lastline;
                    $i--;
                }
                $lastline = array_pop( $mybans ) . $lastline;
                $lastline = array_pop( $mybans ) . $lastline;
                array_push( $mybans, $newline, $lastline );
            } else {
                array_push( $mybans, "\n\n# " . $this->get_http_host() . " Pareto Security Ban\n", "order allow,deny\n", $newline, "allow from all\n", $limitend );
            }
        
            $myfile = fopen( str_replace( '//', DIRECTORY_SEPARATOR, $this->getDir() . DIRECTORY_SEPARATOR . '.htaccess' ), 'w' );
            fwrite( $myfile, implode( $mybans, '' ) );
            fclose( $myfile );
        }
    }
    /**
     * hCoreFileChk()
     * 
     * @return boolean
     */
    function hCoreFileChk( $f = NULL, $r = false, $w = false ) {
        # if file exists return bool
        # if file exists & readable return bool
        # if file exists, readable & writable return bool
        $x = false;
        $f = $this->getDir() . DIRECTORY_SEPARATOR . $f;
        if ( false !== ( bool ) $w )
            $r = true;
        if ( false !== file_exists( $f ) ) {
            $x = true;
        } else
            return false;
        $x = ( false !== ( bool ) $r ) ? is_readable( $f ) : $x;
        $x = ( false !== ( bool ) $w ) ? is_writable( $f ) : $x;
        return ( bool ) $x;
    }
    /**
     * checkfilename()
     * 
     * @param mixed $fname
     * @return
     */
    private static function checkfilename( $fname ) {
        if ( ( false === empty( $fname ) ) && ( ( substr_count( $fname, '.php' ) == 1 ) && ( '.php' == substr( $fname, -4 ) ) ) ) {
            return true;
        } else
            return false;
    }
    /**
     * get_http_host()
     * 
     * @return
     */
    private static function get_http_host( $encoding = 'UTF-8' ) {
        return htmlspecialchars( preg_replace( "/^(?:([^\.]+)\.)?domain\.com$/", '\1', $_SERVER[ 'SERVER_NAME' ] ), ( ( phpversion() >= 5.4 ) ? ENT_HTML5 : ENT_QUOTES ), $encoding );
    }
    /**
     * getPHP_SELF()
     * 
     * @return
     */
    function getPHP_SELF() {
        $filename = NULL;
        $filename = ( ( ( strlen( ini_get( 'cgi.fix_pathinfo' ) ) > 0 ) && ( ( bool ) ini_get( 'cgi.fix_pathinfo' ) == false ) ) || false !== isset( $_SERVER[ 'SCRIPT_NAME' ] ) ) ? basename( $_SERVER[ 'PHP_SELF' ] ) : basename( $_SERVER[ 'SCRIPT_NAME' ] );

        # Need to proposition as type this to death
        if ( false !== ( bool ) $this->string_prop( $filename, 4 ) ) {
            preg_match( "@[a-z0-9_-]+\.php@i", $filename, $matches );
            if ( is_array( $matches ) && array_key_exists( 0, $matches ) && ( '.php' == substr( $matches[ 0 ], -4, 4 ) ) && ( false !== $this->checkfilename( $matches[ 0 ] ) ) && ( $this->hCoreFileChk( $matches[ 0 ], true ) ) ) {
                $filename = $matches[ 0 ];
            }
        }

        if ( false === ( bool ) $this->string_prop( $filename, 4 ) ) {
            $f = explode( '/', __FILE__ );
            $filename = $f[ count( $f )-1 ];
            $filename = ( false !== strpos( $filename, '.php' ) && ( false !== $this->hCoreFileChk( $filename, true ) ) )? $filename : 'index.php';
        }

        if ( false !== ( bool ) $this->string_prop( $filename, 4 ) ) {
            return $filename;
        } else {
            return 'index.php';
        }
    }
   
    /**
     * getDir()
     * 
     * @return
     */
    function getDir() {
        $get_root  = '';
        $rootDir = $this->getREQUEST_URI();
        if ( false !== strpos( $rootDir, DIRECTORY_SEPARATOR ) ) {
            # first we need to find if webroot is a sub directory
            # this can screw things up, if so manually set $_doc_root
            if ( false !== ( bool ) $this->string_prop( $rootDir, 2 ) && ( $rootDir[ 0 ] == DIRECTORY_SEPARATOR ) ) {
                $rootDir = ( ( substr_count( $rootDir, DIRECTORY_SEPARATOR ) > 1 ) ) ? substr( $rootDir, 1 ) : substr( $rootDir, 0 );
                $pos     = strpos( strtolower( $rootDir ), DIRECTORY_SEPARATOR );
                $pos += strlen( '.' ) - 1;
                $rootDir = substr( $rootDir, 0, $pos );
                if ( strpos( $rootDir, '.php' ) || ( strlen( $rootDir ) == 1 ) && ( $rootDir == DIRECTORY_SEPARATOR ) || false !== empty( $rootDir ) ) $rootDir = '';
                if ( ( false !== ( bool ) $this->string_prop( $rootDir, 2 ) && ( substr_count( $rootDir, DIRECTORY_SEPARATOR ) > 0 ) && ( DIRECTORY_SEPARATOR !== substr( $rootDir, -1 ) ) ) ) {
                    $rootDir = DIRECTORY_SEPARATOR . $rootDir . DIRECTORY_SEPARATOR;
                }
            }
        }

        if ( isset( $this->_doc_root ) && ( false !== ( bool ) $this->string_prop( $this->_doc_root, 2 ) ) ) {
            # is set by the user
            $get_root = $this->_doc_root;
        } elseif ( false !== strpos( $_SERVER[ 'DOCUMENT_ROOT' ], 'usr/local' ) || empty( $_SERVER[ 'DOCUMENT_ROOT' ] ) || strlen( $_SERVER[ 'DOCUMENT_ROOT' ] ) < 4 ) {
            # if for some reason there is a problem with DOCUMENT_ROOT, then do this the bad way
            $f     = dirname( __FILE__ );
            $sf    = $_SERVER[ 'SCRIPT_FILENAME' ];
            $fbits = explode( DIRECTORY_SEPARATOR, $f );
            foreach ( $fbits as $a => $b ) {
                if ( false == empty( $b ) && ( false === strpos( $sf, $b ) ) ) {
                    $f = str_replace( $b, '', $f );
                    $f = str_replace( '//', '', $f );
                }
            }
            $get_root = realpath( $f );
        } else {
            $get_root = ( false === empty( $rootDir ) ) ? realpath( $_SERVER[ 'DOCUMENT_ROOT' ] ) . $rootDir : realpath( $_SERVER[ 'DOCUMENT_ROOT' ] );
        }

        return preg_replace( "/wp-admin\/|wp-content\/|wp-include\//i", '', $get_root );
    }
    /**
     * is_server()
     * @return bool
     */
    private static function is_server( $ip ) {
        # tests if ip address accessing webserver
        # is either server ip ( localhost access )
        # or is 127.0.0.1 ( i.e onion visitors )
        if ( ( $_SERVER[ 'SERVER_ADDR' ] == $ip ) || ( $ip == '127.0.0.1' ) )
            return true;
        return false;
    }
    
    /**
     * $this->check_ip()
     * 
     * @param mixed $ip
     * @return
     */
    function check_ip( $ip ) {
        # if ip is the server or localhost
        if ( false !== $this->is_server( $ip ) )
            return true;
        # simple ip format check
        if ( false !== empty( $ip ) || ( strlen( $ip ) < 7 ) ) return false; // 8.8.8.8

        $check = false;
        if ( function_exists( 'filter_var' ) && defined( 'FILTER_VALIDATE_IP' ) && defined( 'FILTER_FLAG_IPV4' ) && defined( 'FILTER_FLAG_IPV6' ) ) {
            if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 || FILTER_FLAG_IPV6 ) ) {
                $check = false;
            } else
                $check = true; //passed the test
        } else {
            # this section should not be necessary in later versions of PHP
            if ( false !== preg_match( "/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/i", $ip ) ) {
                $check = true;

                $parts = explode( '.', $ip );
                $x     = 0;
                while ( $x < count( $parts ) ) {
                    if ( false === $this->integ_prop( $parts[ $x ] ) || ( int ) $parts[ $x ] > 255 ) {
                        $check = false;
                    }
                    $x++;
                }
                if ( ( count( $parts ) <> 4 ) || ( $parts[ 0 ] < 1 ) )
                    $check = false;
            } else
                $check = false;
        }
        if ( false !== ( bool ) $check ) {
            return true;
        } else
            $this->send403();
    }
    /**
     * getRealIP()
     * 
     * @return
     */
    function getRealIP() {
        global $_SERVER;
        $iplist = array();
        # check for IPs passing through proxies
        # start with the HTTP_X_FORWARDED_FOR
        if ( !empty( $_SERVER[ 'HTTP_X_FORWARDED_FOR' ] ) ) {
            # check if multiple ips exist in var
            $x_ff = $_SERVER[ 'HTTP_X_FORWARDED_FOR' ];
            $x_ff = strpos( $x_ff, ',' ) ? str_replace( ' ', '', $x_ff ) : str_replace( ' ', ',', $x_ff );
            if ( false !== strpos( $x_ff, ',' ) ) {
                $iplist = explode( ',', $x_ff );
                # Check the validity of each ip
                foreach ( $iplist as $ip ) {
                    if ( false !== $this->check_ip( $ip ) ) {
                        # if a valid IP then prevent htaccess ban but still allow die()
                        # because X_FORWARDED_FOR is spoofable
                        $this->_bypassbanip = true;
                    }
                }
            }
        }
        
        $serverVars = array(
             'HTTP_FORWARDED_FOR',
             'HTTP_CLIENT_IP',
             'HTTP_X_CLUSTER_CLIENT_IP',
             'HTTP_FORWARDED',
             'HTTP_CF_CONNECTING_IP' 
        );
        
        # the point here is to not accidentally ban an ip address that could
        # be an upline proxy, instead just allow a page die() action if
        # request is malicious
        $x = 0;
        while ( $x < count( $serverVars ) ) {
            if ( array_key_exists( $serverVars[ $x ], $_SERVER ) && !empty( $_SERVER[ $serverVars[ $x ] ] ) && false !== $this->check_ip( $_SERVER[ $serverVars[ $x ] ] ) ) {
                # if a valid IP then prevent htaccess ban but still allow die()
                # because all of the above are spoofable
                $this->_bypassbanip = true;
            }
            $x++;
        }
        # never trust any headers except REMOTE_ADDR
        return $this->getREMOTE_ADDR();
    }
    
    function setOpenBaseDir() {
        if ( false === ( bool ) $this->_open_basedir ) return;
        if ( strlen( ini_get( 'open_basedir' ) == 0 ) ) {
            return @ini_set( 'open_basedir', $this->getDir() );
        }
    }
    /**
     * x_secure_headers()
     */
    function x_secure_headers() {
        $errlevel = ini_get( 'error_reporting' );
        error_reporting( 0 );
        header( 'strict-transport-security: max-age=31536000; includeSubDomains; preload' );
        header( 'access-control-allow-methods: POST, GET' );
        header( 'x-frame-options: SAMEORIGIN' );
        header( 'x-content-type-options: nosniff' );
        header( 'x-xss-protection: 1; mode=block' );
        if ( false !== ( bool ) ini_get( 'expose_php' ) || 'on' == strtolower( @ini_get( 'expose_php' ) ) ) {
            header( 'X-Powered-By: Pareto Security - http://hokioisec7agisc4.onion' );
        }
        error_reporting( $errlevel );
        return;
    }
    /**
     * url_decoder()
     */
    function url_decoder( $var ) {
        return rawurldecode( urldecode( str_replace( chr( 0 ), '', $var ) ) );
    }
    /**
     * getREQUEST_URI()
     */
    function getREQUEST_URI() {
        if ( false !== getenv( 'REQUEST_URI' ) && ( false !== ( bool ) $this->string_prop( getenv( 'REQUEST_URI' ), 2 ) ) ) {
            return getenv( 'REQUEST_URI' );
        } else {
            return $_SERVER[ 'REQUEST_URI' ];
        }
    }
    /**
     * getREMOTE_ADDR()
     */
    function getREMOTE_ADDR() {
        if ( false !== getenv( 'REMOTE_ADDR' ) && ( false !== ( bool ) $this->string_prop( getenv( 'REMOTE_ADDR' ), 7 ) ) && false !== $this->check_ip( getenv( 'REMOTE_ADDR' ) ) ) {
            return getenv( 'REMOTE_ADDR' );
        } elseif ( false !== $_SERVER( 'REMOTE_ADDR' ) && ( false !== ( bool ) $this->string_prop( $_SERVER( 'REMOTE_ADDR' ), 6 ) ) && false !== $this->check_ip( $_SERVER( 'REMOTE_ADDR' ) ) ) {
            return $_SERVER[ 'REMOTE_ADDR' ];
        }
    }
    /**
     * getQUERY_STRING()
     */
    function getQUERY_STRING() {
        if ( false !== getenv( 'QUERY_STRING' ) ) {
            return strtolower( $this->url_decoder( getenv( 'QUERY_STRING' ) ) );
        } else {
            return strtolower( $this->url_decoder( $_SERVER[ 'QUERY_STRING' ] ) );
        }
    }
    /**
     * string_prop()
     */
    private static function string_prop( $str, $len=0 ) {
        # is not an array, is a string, is of at least a specified length ( default is greater than 0 )
        if ( false !== is_array( $str ) ) return false;
        $x = ( is_string( $str ) ) ? ( ( strlen( $str ) >= ( int ) $len ) ? true: false ): false;
        return ( bool ) $x;
    }
    /**
     * integ_prop()
     */
    private static function integ_prop( $integer ) {
        # is an integer, is not a float, is not negative
        if ( false !== is_int( $integer ) && false !== preg_match( '/^\d+$/D', $integer ) && ( int ) $integer >= 0 ) {
            return true;
        } else {
            return false;
        }
    }
} // end of class

// Initialize our plugin object.
global $ParetoSecurity;
if ( class_exists( 'ParetoSecurity' ) && !$ParetoSecurity ) {
    $ParetoSecurity = new ParetoSecurity();
}

function load_pareto_first() {
    $wp_path_to_this_file = preg_replace( '/(.*)plugins\/(.*)$/', WP_PLUGIN_DIR . "/$2", __FILE__ );
    $this_plugin          = plugin_basename( trim( $wp_path_to_this_file ) );
    $active_plugins       = get_option( 'active_plugins' );
    $this_plugin_key      = array_search( $this_plugin, $active_plugins );
    if ( $this_plugin_key ) {
        array_splice( $active_plugins, $this_plugin_key, 1 );
        array_unshift( $active_plugins, $this_plugin );
        update_option( 'active_plugins', $active_plugins );
    }
}
?>
