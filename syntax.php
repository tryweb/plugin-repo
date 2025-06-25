<?php
/**
 * Repository Plugin: show files from a remote repository with GesHi syntax highlighting
 * Syntax: {{repo>[url] [cachetime]|[title]}}
 * [url]       - (REQUIRED) base URL of the code repository
 * [cachetime] - (OPTIONAL) how often the cache should be refreshed;
 * a number followed by one of these chars:
 * d for day, h for hour or m for minutes;
 * the minimum accepted value is 10 minutes.
 * [title]     - (OPTIONAL) a string to display as the base path
 * of the repository.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 * @author     Christopher Smith <chris@jalakai.co.uk>
 * @author     Doug Daniels <Daniels.Douglas@gmail.com>
 * @author     Myron Turner <turnermm02@shaw.ca>
 */

// must be run inside DokuWiki
if (!defined('DOKU_INC')) {
    die();
}

if (!defined('DOKU_PLUGIN')) {
    define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
}

// Explicitly require the file where the HTTP client class is defined
// Using the path found on your system: /app/www/public/inc/HTTP/HTTPClient.php
require_once(DOKU_INC . 'inc/HTTP/HTTPClient.php');

// Import the correct HTTPClient class
use dokuwiki\HTTP\HTTPClient; // <--- CHANGED FROM DokuHttpClient TO HTTPClient

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_repo extends DokuWiki_Syntax_Plugin
{
    function getType()
    {
        return 'substition';
    }
    function getSort()
    {
        return 301;
    }
    function getPType()
    {
        return 'block';
    }
    function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern("{{repo>.+?}}", $mode, 'plugin_repo');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 7, -2); // Remove {{repo> and }}

        $parts_title = explode('|', $match, 2);
        $base_and_refresh = $parts_title[0];
        $title = isset($parts_title[1]) ? $parts_title[1] : '';

        $parts_refresh = explode(' ', $base_and_refresh, 2);
        $base = $parts_refresh[0];
        $refresh_str = isset($parts_refresh[1]) ? $parts_refresh[1] : '';

        if (preg_match('/(\d+)([dhm])/', $refresh_str, $match_refresh)) {
            $period = array('d' => 86400, 'h' => 3600, 'm' => 60);
            // n * period in seconds, minimum 10 minutes (600 seconds)
            $refresh = max(600, (int) $match_refresh[1] * $period[$match_refresh[2]]);
        } else {
            // default to 4 hours (14400 seconds)
            $refresh = 14400;
        }

        return array(trim($base), trim($title), $pos, $refresh);
    }

    /**
     * Create output
     */
    function render($mode, Doku_Renderer $renderer, $data)
    {
        $ok = true; // Initialize $ok

        // construct requested URL
        $base = hsc($data[0]);
        $title = ($data[1] ? hsc($data[1]) : $base);
        // Ensure $_REQUEST['repo'] is set before using it
        $path = isset($_REQUEST['repo']) ? hsc($_REQUEST['repo']) : '';
        $url = $base . $path;

        if ($mode == 'xhtml') {
            // prevent caching to ensure the included page is always fresh
            $renderer->info['cache'] = false;

            // output
            $renderer->header($title . $path, 5, $data[2]);
            $renderer->section_open(5);
            if (!empty($url) && $url[strlen($url) - 1] == '/') { // directory
                $this->_directory($base, $renderer, $path, $data[3]);
            } elseif (preg_match('/(jpe?g|gif|png)$/i', $url)) { // image
                $this->_image($url, $renderer);
            } else { // source code file
                $this->_codefile($url, $renderer, $data[3]);
            }
            if ($path) {
                $this->_location($path, $title, $renderer);
            }
            $renderer->section_close();
        } elseif ($mode == 'metadata') {
            $renderer->meta['relation']['haspart'][$url] = 1;
        }

        return $ok;
    }

    /**
     * Handle remote directories
     */
    function _directory($url, &$renderer, $path, $refresh)
    {
        global $conf;

        $cache = getCacheName($url . $path, '.repo');
        $mtime = @filemtime($cache); // 0 if it doesn't exist

        // Check for $_REQUEST['purge'] before using it
        $do_not_purge = !isset($_REQUEST['purge']);

        if (($mtime != 0) && $do_not_purge && ($mtime > time() - $refresh)) {
            $idx = io_readFile($cache, false);
            if ($conf['allowdebug']) {
                $idx .= "\n\n";
            }
        } else {
            $items = $this->_index($url, $path);
            $idx = html_buildlist($items, 'idx', 'repo_list_index', 'html_li_index');

            io_saveFile($cache, $idx);
            if ($conf['allowdebug']) {
                $idx .= "\n\n";
            }
        }

        $renderer->doc .= $idx;
    }

    /**
     * Extract links and list them as directory contents
     */
    function _index($url, $path, $base = '', $lvl = 0)
    {
        // download the index html file
        $http = new HTTPClient(); // <--- CHANGED FROM DokuHttpClient TO HTTPClient
        $http->timeout = 25; //max. 25 sec
        $data = $http->get($url . $base);

        // Check if data was successfully retrieved
        if ($data === false) {
            return array(); // Return empty array if download failed
        }

        preg_match_all('/<li><a href="(.*?)">/i', $data, $results);

        $lvl++;
        $items = array(); // Initialize items array
        foreach ($results[1] as $result) {
            if ($result == '../') {
                continue;
            }

            $type = (substr($result, -1) == '/' ? 'd' : 'f');
            $open = (($type == 'd') && (strpos($path, $base . $result) === 0));
            $items[] = array(
                'level' => $lvl,
                'type' => $type,
                'path' => $base . $result,
                'open' => $open,
            );
            if ($open) {
                $items = array_merge($items, $this->_index($url, $path, $base . $result, $lvl));
            }
        }
        return $items;
    }

    /**
     * Handle remote images
     */
    function _image($url, &$renderer)
    {
        $renderer->p_open();
        $renderer->externalmedia($url, NULL, NULL, NULL, NULL, 'recache');
        $renderer->p_close();
    }

    /**
     * Handle remote source code files: display as code box with link to file at the end
     */
    function _codefile($url, &$renderer, $refresh)
    {
        // output the code box with syntax highlighting
        $renderer->doc .= $this->_cached_geshi($url, $refresh);

        // and show a link to the original file
        $renderer->p_open();
        $renderer->externallink($url);
        $renderer->p_close();
    }

    /**
     * Wrapper for GeSHi Code Highlighter, provides caching of its output
     * Modified to calculate cache from URL so we don't have to re-download time and again
     *
     * @author Christopher Smith <chris@jalakai.co.uk>
     * @author Esther Brunner <wikidesign@gmail.com>
     */
    function _cached_geshi($url, $refresh)
    {
        global $conf;

        $cache = getCacheName($url, '.code');
        $mtime = @filemtime($cache); // 0 if it doesn't exist

        // Check for $_REQUEST['purge'] before using it
        $do_not_purge = !isset($_REQUEST['purge']);

        if (($mtime != 0) && $do_not_purge &&
            ($mtime > time() - $refresh) &&
            ($mtime > filemtime(DOKU_INC . 'vendor/geshi/geshi/src/geshi.php'))
        ) {
            $hi_code = io_readFile($cache, false);
            if ($conf['allowdebug']) {
                $hi_code .= "\n\n";
            }
        } else {
            require_once(DOKU_INC . 'vendor/geshi/geshi/src/geshi.php');

            // get the source code language first
            $search = array('/^htm/', '/^js$/');
            $replace = array('html4strict', 'javascript');
            $lang = preg_replace($search, $replace, substr(strrchr($url, '.'), 1));

            // download external file
            $http = new HTTPClient(); // <--- CHANGED FROM DokuHttpClient TO HTTPClient
            $http->timeout = 25; //max. 25 sec
            $code = $http->get($url);

            // Check if code was successfully retrieved
            if ($code === false) {
                return '<p class="error">Failed to retrieve code from ' . hsc($url) . '</p>';
            }

            $geshi = new GeSHi($code, strtolower($lang), DOKU_INC . 'vendor/geshi/geshi/src/geshi');
            $geshi->set_encoding('utf-8');
            $geshi->enable_classes();
            $geshi->set_header_type(GESHI_HEADER_PRE);
            // Correct the variable name from $language to $lang
            $geshi->set_overall_class("code " . strtolower($lang));
            $geshi->set_link_target($conf['target']['extern']);

            $hi_code = $geshi->parse_code();

            io_saveFile($cache, $hi_code);
            if ($conf['allowdebug']) {
                $hi_code .= "\n\n";
            }
        }

        return $hi_code;
    }

    /**
     * Show where we are with link back to main repository
     */
    function _location($path, $title, &$renderer)
    {
        global $ID;

        $renderer->p_open();
        $renderer->internallink($ID, $title);

        $base = '';
        $dirs = explode('/', $path);
        // Only iterate up to the second to last element for directories
        $n = count($dirs);
        for ($i = 0; $i < $n - 1; $i++) {
            $base .= hsc($dirs[$i]) . '/';
            $renderer->doc .= '<a href="' . wl($ID, 'repo=' . $base) . '" class="idx_dir">' .
                hsc($dirs[$i]) . '/</a>';
        }
        // Display the last part (file or empty string if it's a directory path ending with /)
        if (isset($dirs[$n - 1])) {
            $renderer->doc .= hsc($dirs[$n - 1]);
        }
        $renderer->p_close();
    }
}

/**
 * For html_buildlist()
 */
function repo_list_index($item)
{
    global $ID;

    if ($item['type'] == 'd') {
        $title = substr($item['path'], 0, -1);
        $class = 'idx_dir';
    } else {
        $title = $item['path'];
        $class = 'wikilink1';
    }
    // Ensure title is extracted correctly, especially for base directories
    $display_title = substr(strrchr('/' . $title, '/'), 1);
    // If the title is empty after strrchr (e.g., for root directory), use the original path
    if (empty($display_title) && !empty($title)) {
        $display_title = $title;
    }

    return '<a href="' . wl($ID, 'repo=' . $item['path']) . '" class="' . $class . '">' . $display_title . '</a>';
}
// vim:ts=4:sw=4:et:enc=utf-8: