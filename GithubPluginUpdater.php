<?php  


if ( !class_exists( 'WpGithubPluginUpdater' ) ) {
    /**
     * The WP Github Plugin Updater Class
     * ===
     * 
     * Hooks into standard Wordpress functionality for plugins being hosted on
     * Github. Uses the Github API to find and supply the necessary data. Only 
     * updates every 10 hours to minimize hits to Github and load times.
     * 
     * ## Usage
     * 
     * To install and use this class, simply include the file in your main plugin file
     * and instantiate an object of the class, passing your plugin file's path as the
     * only required argument. Then hook those lines in to the `admin_init` action.
     * 
     * Example:
     * 
     * ```php
     * <?php
     * function YourPlugin_github_updater () {
     *     include('path/to/github-updater.php');
     *     $updater = new WpGithubPluginUpdater(__FILE__);
     * }
     * add_action('admin_init', "YourPlugin_github_updater");
     * ```
     * 
     * @version     0.0.1
     * @license     GPLv2 or later <http://www.gnu.org/licenses/gpl-2.0-standalone.html>
     * @link        <https://github.com/crockett95> Author's Github Account
     * @link        <https://crockett95.github.io>  Author's Website
     * @author      Stephen Crockett    <crockett95@gmail.com>
     * @copyright   (c) 2014, Stephen Crockett
     * 
     * GNU General Public License, Free Software Foundation
     * <http://creativecommons.org/licenses/GPL/2.0/>
     * 
     * This program is free software: you can redistribute it and/or modify
     * the Free Software Foundation, either version 2 of the License, or
     * (at your option) any later version.
     * 
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
     * GNU General Public License for more details.
     * 
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <http://www.gnu.org/licenses/>.
     */
    class WpGithubPluginUpdater {
        /**
         * Holds an array of all the plugins using this class
         */
        static $pluginsArray = array();
        
        /**
         * Holds the private key for the Github API, if entered
         * @var bool/string
         */
        static $githubPrivateKey = get_option('wpGithubPluginUpdater_apiKey');

        /**
         * Constants for setting the release channel. Defaults to PRODUCTION
         */
        const PRODUCTION    = 40;
        const BETA          = 30;
        const ALPHA         = 20;
        const DEV           = 10;

        /**
         * Allowed tags for different levels of release
         * @var array
         */
        private $releaseTypeTags = array(
            'dev'   =>  0,
            'alpha' =>  10,
            'a'     =>  10,
            'beta'  =>  20,
            'b'     =>  20,
            'rc'    =>  30,
            'p'     =>  40
        );
        
        /**
         * Holds the slug of the plugin, equal to the basename of the main plugin file
         * @var string
         */
        private $slug = '';
        
        /**
         * Holds the file path of the main plugn file
         * @var string
         */
        private $pluginFile = '';
        
        /**
         * Holds the release channel to be used, as an integer representation
         * @var integer
         */
        private $releaseChannel = null;
        
        /**
         * The subdirectory and filename of the plugin
         * @var string
         */
        private $subDir = '';
        
        /**
         * The header data from the main plugin file
         * @var array
         */
        private $fileHeaderData = null;
        
        /**
         * The name of the github repo in the form of `<user>/<repoName>`
         * @var string
         */
        private $githubRepo = '';
        
        /**
         * Whether to search tags or only releases in a repo
         * @var string
         */
        private $useTags = true;
        
        /**
         * The name of the github README file
         * @var string
         */
        private $githubReadmeFile = '';
        
        /**
         * The contents of the README file
         * @var string
         */
        private $githubReadme = '';
        
        /**
         * Has the README been checked since the transient was flushed
         * @var boolean
         */
        private $githubReadmeChecked = false;
        
        /**
         * The contents of the README file converted to HTML
         * @var string
         */
        private $githubReadmeHtml = '';
        
        /**
         * The object returned containing all the Github info
         * @var stdClass
         */
        private $githubRepoData = null;
        
        /**
         * All the releases, newest to oldest, as an array of objects
         * @var array
         */
        private $releases = null;
        
        /**
         * The latest version info from Github
         * @var stdClass
         */
        private $latestVersion = null;
        
        /**
         * The set of default arguments for Github API requests
         * @var array
         */
        private $defaultRequestArgs = array(
            'sslverify' => true
        );
        
        /**
         * Sets the base information about the plugin and stores it to a transient
         * 
         * Initializes all the plugin data, including all Github data and stores it in a transient with a 
         * 10 hr TTL. Wordpress checks for plugins every 12 hours by default so this insures that there will
         *  be fresh data for each check but also that the API will not be abused
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @param   string  $mainFile       The file path of the main plugin file
         * @param   boolean $apiTags        Search tags for latest release
         * @param   integer $releaseChannel What release channel to get updates on
         * @param   string  $readmeFileName Defaults to README.md
         * 
         * @uses    WpGithubPluginUpdater::actionsAndFilters()
         * @uses    WpGithubPluginUpdater::setGithubRepoName()
         * @uses    WpGithubPluginUpdater::retrieveGithubRepo()
         * @uses    WpGithubPluginUpdater::retrieveGithubReleases()
         * @uses    WpGithubPluginUpdater::findLatestRelease()
         * 
         * @uses    WpGithubPluginUpdater::$pluginFile
         * @uses    WpGithubPluginUpdater::$releaseChannel
         * @uses    WpGithubPluginUpdater::$subDir
         * @uses    WpGithubPluginUpdater::$slug
         * @uses    WpGithubPluginUpdater::$fileHeaderData
         * @uses    WpGithubPluginUpdater::$githubRepo
         * @uses    WpGithubPluginUpdater::$githubRepoData
         * @uses    WpGithubPluginUpdater::$releases
         * @uses    WpGithubPluginUpdater::$latestVersion
         */
        function __construct ( $mainFile, $apiTags = true, $releaseChannel = self::PRODUCTION, $readmeFileName = 'README.md' ) {
            
            //  No sense in checking if the user can't do anything with it
            if ( !current_user_can('update_plugins') ) {
                return;
            }
            
            //  Add Filters
            $this->actionsAndFilters();
            
            //  Initialize fields
            $this->pluginFile = $mainFile;
            $this->releaseChannel = strtolower( trim( $releaseChannel ) );
            $this->subDir = plugin_basename( $mainFile );
            $this->useTags = $apiTags;
            
            list ($t1, $t2) = explode('/', $this->subDir);  
            $this->slug = str_replace('.php', '', $t2);
            $this->githubReadmeFile = $readmeFileName;
            
            $savedData = get_site_transient( $this->slug . "-github-upgrade-data" );
            
            if ( $savedData ) {
                $savedData = maybe_unserialize( $savedData );
                
                $this->fileHeaderData   = $savedData->fileHeaderData;
                $this->githubRepo       = $savedData->githubRepo;
                $this->githubRepoData   = $savedData->githubRepoData;
                $this->releases         = $savedData->releases;
                $this->latestVersion    = $savedData->latestVersion;
                $this->githubReadmeChecked = $savedData->githubReadmeChecked;
                $this->githubReadme     = $savedData->githubReadme;
                $this->githubReadmeHtml = $savedData->githubReadmeHtml;
            } else {
                $this->fileHeaderData   = get_plugin_data( $mainFile );
                $this->githubRepo       = $this->setGithubRepoName();
                $this->githubRepoData   = $this->retrieveGithubRepo();
                $this->releases         = $this->retrieveGithubReleases();
                $this->latestVersion    = $this->findLatestRelease();
                
                //  If there's no data we don't need to save it
                if ( $this->fileHeaderData && $this->githubRepoData && $this->releases ) {
                    set_site_transient( $this->slug . "-github-upgrade-data", $this, 1 );
                }
            }
        }
        
        /**
         * Adds all the actions and filters necessary for the updater
         * 
         * @version 0.0.0
         * @since   0.0.0
         */
        private function actionsAndFilters() {
            
            add_filter( 'extra_plugin_headers', array( $this, 'filterPluginHeaders' ) );
            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkForUpdate' ) );
            
            //  TODO Add 'plugins_api' filter with hook to README
        }
        
        /**
         * Filters plugin header files to make sure Github URI is returned
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @param   array   $extraPluginData    What we want to add
         * @return  array                       Modified array
         */
        public function filterPluginHeaders ( $extraPluginData ) {
            $extraPluginData[] = 'GitHub Plugin URI';
            
            return $extraPluginData;
        }
        
        /**
         * Sets the repo name for use by other methods
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @uses    WpGithubPluginUpdater::$fileHeaderData  to get the Github URI
         */
        private function setGithubRepoName () {
            
            if ( empty( $this->fileHeaderData['GitHub Plugin URI'] ) ) {
                return false;
            }
            
            $githubUrl = $this->fileHeaderData['GitHub Plugin URI'];
            return substr( untrailingslashit( parse_url( $githubUrl, PHP_URL_PATH ) ), 1 );
        }
        
        /**
         * Retrieves the general Github repo information
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @uses    WpGithubPluginUpdater::$githubRepo          To construct URI
         * @uses    WpGithubPluginUpdater::$defaultRequestArgs  To set request arguments
         * @return  stdClass|boolean    Returns parsed data if successful, or `false` if not
         */
        private function retrieveGithubRepo () {            

            $githubPath = 'https://api.github.com/repos/' . $this->githubRepo;
            $githubRaw = wp_remote_get( $githubPath, $this->defaultRequestArgs );
            if (!is_wp_error($githubRaw) 
                    && $githubRaw['response']['code'] == 200) {
                $response = json_decode( $githubRaw['body'] );
                if (isset($response->message) && !empty($response->message)) {
                    return false;
                }
                
                return $response;
            } else {
                return false;
            }
            
            return false;
        }
        
        /**
         * Retrieves the feed of releases from Github
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @uses    WpGithubPluginUpdater::$githubRepoData      To retrieve releases feed URI
         * @uses    WpGithubPluginUpdater::$defaultRequestArgs  To set request arguments
         * @return  array|boolean   If the request is successful, returns array of release objects,
         *                          otherwise returns false
         */
        private function retrieveGithubReleases () {
            //  Github repo data failed, so we stop
            if ( empty( $this->githubRepoData ) ) {
                return false;
            }
            
            //  Get the releases URI
            if ( $this->useTags ) {
                $releaseUrlRaw = $this->githubRepoData->tags_url;
            } else {
                $releaseUrlRaw = $this->githubRepoData->releases_url;
            }
            
            //  Take off any of the `optional` flags
            $releaseUrl = preg_replace( '/\{[^\}]*\}/', '', $releaseUrlRaw );
            
            //  Send the request
            $githubRaw = wp_remote_get( $releaseUrl, $this->defaultRequestArgs );
            
            //  If it was an error, we don't want it
            if ( is_wp_error($githubRaw)
                    || $githubRaw['response']['code'] != 200 ) {
                return false;
            }
            
            //  Return the body
            return json_decode( $githubRaw['body'] );
        }
        
        
        /**
         * Filters the releases to get the most current
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @uses    WpGithubPluginUpdater::$releases        The list to filter
         * @uses    WpGithubPluginUpdater::$releaseChannel  To check against
         * @uses    WpGithubPluginUpdater::$releaseTypeTags To check for
         * @return  stdClass|boolean    Returns the release object, or false if the releases aren't there
         */
        private function findLatestRelease() {
            //  If we don't have any releases, stop looking
            if ( !$this->releases ) {
                return false;
            }
            
            //  The newest is first, so we'll check newest to oldest
            foreach ( $this->releases as $release ) {
                //  Get a version number we can use
                $version = trim( strtolower( $release->name ) );
                $version = str_replace( ' ', '', $version );
                
                //  Strip any prefixes
                $version = preg_replace( '/^[a-z]*/', '', $version );
                
                //  If it doesn't have a tag, it's the production release
                if ( !preg_match( '/^[\.\d]+[a-z]+[\d]+/', $version ) ) {
                    return $release;
                }
                
                //  If it checks against a lower release channel in the tags
                //  then we don't want it, but otherwise, it might be a patch or something
                $lowerReleaseChannel = false;
                
                //  Check the release tags (alpha, beta, dev)
                foreach ( $this->releaseTypeTags as $tag => $level ) {
                    
                    //  If it matches, then we check what release channel we're on
                    if ( preg_match( '/^[\.\d]+' . $tag . '[\d]+/', $version)) {
                        //  If it's a higher release than our minimum, we want it
                        if ( $level >= $this->releaseChannel ) {
                            return $release;
                        } else {
                            //  If it's a lower release we don't want to return it
                            $lowerReleaseChannel = true;
                            break;
                        }
                    }
                }
                
                //  Other tags are assumed patches, etc
                if (!$lowerReleaseChannel) {
                    return $release;
                }
            }
        }

        
        /**
         * If there's a new version, filters WP transient for update
         * 
         * Called when Wordpress checks for updates. If the latest version in the Github repo is newer than the 
         * currently installed version, it modifies the transient to alert the update in the front-end.
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @param   stdClass    $transient  The WP transient object with update information
         * @return  stdClass                Returns immediately if no change, or modified
         * @uses    WpGithubPluginUpdater::$latestVersion   The latest version info
         * @uses    WpGithubPluginUpdater::$fileHeaderData  Contains currently installed version data
         * @uses    WpGithubPluginUpdater::$slug            Sets the slug in $transient
         * @uses    WpGithubPluginUpdater::$subDir          Corresponds to WP's keys in $transient->response
         */
        public function checkForUpdate ($transient) {
            //  If the latest release isn't there, there's no point
            if ( !$this->latestVersion ) {
                return $transient;
            }
            
            //  If the releases match, there's nothing to update
            if( version_compare( preg_replace( '/^[a-z]*/', '', $this->latestVersion->name ), $this->fileHeaderData['Version'], 'lte' ) ) {
                return $transient;
            }
            
            //  There's a newer release, so build the object
            $pluginResponse = new stdClass();
            $pluginResponse->slug = $this->slug;
            $pluginResponse->new_version = preg_replace( '/^[a-z]*/', '', $this->latestVersion->name );
            $pluginResponse->url = $this->latestVersion->zipball_url;
            $pluginResponse->package = $this->latestVersion->zipball_url;
            
            //  This is a good time to check the README because we know we need to
            if ( empty( $this->githubReadme) && !$this->githubReadmeChecked ) {
                $this->githubReadme     = $this->getLatestReadme();
                $this->githubReadmeHtml = $this->setLatestReadmeHtml();
                $this->githubReadmeChecked = true;
            }
            
            //  Append the object to the response array
            $transient->response[$this->subDir] = $pluginResponse;
            
            return $transient;
        }
        
        /**
         * Converts a string of Github-flavored Markdown to HTML, in the context of the plugin's repository
         * 
         * @version 0.0.0
         * @since   0.0.0
         * @param   string  $markdown   The string of markdown text
         * @return  string|boolean      The converted string of HTML, or false on error
         * @uses    WpGithubPluginUpdater::$githubRepo          To set the Github Repo context
         * @uses    WpGithubPluginUpdater::$defaultRequestArgs  To set the HTTP request arguments
         */
        public function convertGithubMarkdown ( $markdown ) {
            
            //  API URI
            $requestUri = 'https://api.github.com/markdown';
            
            //  Set these up
            $requestArgs = array(
                'headers' => array(
                    'Content-Type: application/x-www-form-urlencoded'
                ),
                'body' => '{"text": "' . str_replace('"', '\"', $markdown) . '", "mode": "gfm", "context": "' . $this->githubRepo . '"}'
            );
            
            //  Merge with defaults
            $requestArgs = array_merge( $requestArgs, $this->defaultRequestArgs );
            
            //  Make the request
            $response = wp_remote_post( $requestUri, $requestArgs );
            
            //  Check that we actually got back what we want
            if ( is_wp_error( $response ) || $response['response']['code'] != 200) {
                return false;
            }
            
            return $response['body'];
        }

        
        /**
         * Gets the README file from the GitHub repository
         * 
         * @version 0.0.1
         * @since   0.0.1
         * @return  string|boolean      The README body, or false on error
         * @uses    WpGithubPluginUpdater::$githubRepo          To set the Github Repo context
         * @uses    WpGithubPluginUpdater::$latestVersion       To get the right file
         * @uses    WpGithubPluginUpdater::$githubReadmeFile    To get the right file
         * @uses    WpGithubPluginUpdater::$defaultRequestArgs  To set the HTTP request arguments
         */
        private function getLatestReadme () {
            $readmeURL = 'https://raw.github.com/' . $this->githubRepo . '/' . $this->latestVersion->tag_name . '/' . $this->githubReadmeFile;
            
            $readme = wp_remote_get( $readmeURL, $this->defaultRequestArgs );
            
            if ( is_wp_error( $readme ) || $readme['response']['code'] != 200 ) {
                return false;
            }
            
            return $readme['body'];
        }

        
        /**
         * Gets an HTML version of the readme
         * 
         * @version 0.0.1
         * @since   0.0.1
         * @return  string|boolean      The README body, or false on error
         * @uses    WpGithubPluginUpdater::$githubReadmeFile    To see if it's markdown
         * @uses    WpGithubPluginUpdater::$githubReadme        To get the contents
         * @uses    WpGithubPluginUpdater::$defaultRequestArgs  To set the HTTP request arguments
         * @uses    WpGithubPluginUpdater::convertGithubMarkdown()  To get the converted source
         */
        private function setLatestReadmeHtml () {
            if ( !$this->githubReadme ) { 
                return false;
            }
            if ( substr(strtolower($this->githubReadmeFile), -3) != '.md' ) {
                return sprint( '<pre>%s</pre>', $this->githubReadme );
            }
            
            $parsedReadme = $this->convertGithubMarkdown( $this->githubReadme );
            return ( $parsedReadme ?: false );
        }

        
        /**
         * Filters the plugins_api data
         * 
         * @version 0.0.1
         * @since   0.0.1
         * @param   mixed   $object What gets filtered
         * @param   string  $action Why it got called
         * @param   array   $args   The args it got called with
         * @return  stdClass
         * @todo
         */
        public function pluginApiData ($object, $action, $args) {
            $pluginData = new stdClass();
            
            //  $array = preg_split ('/$\R?^/m', $string);
            
            $pluginData->name;
            $pluginData->version;
            $pluginData->download_link;
            $pluginData->sections = array('name' => 'content');
            $pluginData->author;
            $pluginData->requires;
            $pluginData->tested;
            $pluginData->homepage;
            $pluginData->downloaded;
            $pluginData->slug;
            $pluginData->last_updated;
            $pluginData->rating;
            
            return $pluginData;
        }
    }
}
