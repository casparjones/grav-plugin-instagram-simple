<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Data\Data;
use Grav\Common\Page\Page;
use Grav\Common\GPM\Response;

/**
 * Class InstagramSimplePlugin
 * @package Grav\Plugin
 */
class InstagramSimplePlugin extends Plugin
{
    private $template_html = 'partials/instagram.html.twig';
    private $template_vars = [];
    private $cache;
    const HOUR_IN_SECONDS = 3600;

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize configuration.
     */
    public function onPluginsInitialized()
    {
        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0]
        ]);
    }

    /**
     * Add Twig Extensions.
     */
    public function onTwigInitialized()
    {
        $this->grav['twig']->twig->addFunction(new \Twig_SimpleFunction('instagram_feed', [$this, 'getFeed']));
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * @return array
     */
    public function getFeed($params = [])
    {
        /** @var Page $page */
        $page = $this->grav['page'];
        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        /** @var Data $config */
        $config = $this->mergeConfig($page, TRUE);

        // Autoload composer components
        require __DIR__ . '/vendor/autoload.php';

        // Set up cache settings
        $cache_config = array(
            "storage"   =>  "files",
            "default_chmod" => 0777,
            "fallback" => "files",
            "securityKey" => "auto",
            "htaccess" => true,
            "path" => __DIR__ . "/cache"
        );

        // Init the cache engine
        $this->cache = phpFastCache("files", $cache_config);

        // Generate API url
        $url = 'https://www.instagram.com/' . $config->get('feed_parameters.user_id') . '/?__a=1'; //https://api.instagram.com/v1/users/self/media/recent/?access_token=' . $config->get('feed_parameters.access_token').'&count=' . $config->get('feed_parameters.count');

        // Get the cached results if available
        $results = $this->cache->get($url);

        // Get the results from the live API, cached version not found
        if ($results === null) {
            $results = Response::get($url);

            // Cache the results
            $this->cache->set($url, $results, InstagramSimplePlugin::HOUR_IN_SECONDS * $config->get('feed_parameters.cache_time')); // Convert hours to seconds
        }

        $this->parseResponse($results);

        $this->template_vars = [
            'user_id'   => $config->get('feed_parameters.user_id'),
            'client_id' => $config->get('feed_parameters.client_id'),
            'feed'      => $this->feeds,
            'count'     => $config->get('feed_parameters.count'),
            'params'    => $params
        ];

        $output = $this->grav['twig']->twig()->render($this->template_html, $this->template_vars);

        return $output;
    }

    private function addFeed($result) {
        foreach ($result as $key => $val) {
            if (!isset($this->feeds[$key])) {
                $this->feeds[$key] = $val;
            }
        }
        krsort($this->feeds);
    }

    private function parseResponse($json) {
        $r = array();
        $content = json_decode($json, true);
        $user = $content['graphql']['user'];
        $edges = $user['edge_owner_to_timeline_media']['edges'];
        if (count($edges)) {
            foreach ($edges as $key => $node) {
                if(isset($node['node'])) {
                    $val = $node['node'];
                    $created_at = $val['taken_at_timestamp'];
                    $r[$created_at]['time'] = $created_at;
                    $r[$created_at]['text'] = $val['edge_media_to_caption']['edges'][0]['node']['text'];
                    $r[$created_at]['image'] = $val['display_url'];
                    $r[$created_at]['image_width'] = $val['dimensions']['width'];
                    $r[$created_at]['thumb'] = $val['thumbnail_resources'][1]['src'];
                    $r[$created_at]['thumb_width'] = $val['thumbnail_resources'][1]['config_width'];
                    $r[$created_at]['micro'] = $val['thumbnail_resources'][0]['src'];
                    $r[$created_at]['micro_width'] = $val['thumbnail_resources'][0]['src'];
                    $r[$created_at]['user'] = $user['full_name'];
                    $r[$created_at]['link'] = "https://www.instagram.com/p/" . $val['shortcode'];
                    $r[$created_at]['comments'] = $val['edge_media_to_comment']['count'];
                    $r[$created_at]['likes'] = $val['edge_liked_by']['count'];
                    $r[$created_at]['type'] = $val['__typename'];
                }
            }
            
            $this->addFeed($r);
        }
    }
}
