<?php

/*
Plugin Name: Flux Analytics WP
Plugin URI: http://ppss.kr
Description: A simple meta analytics dashboard.
Version: 0.0.1
Author: Lee Han Kyeol
Author URI: http://leehankyeol.me
License: GPL2
*/

defined('ABSPATH') or die('No script kiddies please!');

class FluxAnalytics
{
    protected $PAGE_SLUG = 'flux_analytics';
    protected $MAX_DAYS_TO_DISPLAY = 7;

    protected $FB_PAGE_ID;
    protected $FB_APP_ID;
    protected $FB_APP_SECRET;
    protected $FB_ACCESS_TOKEN;

    protected $GA_SERVICE_ACCOUNT_EMAIL;
    protected $GA_VIEW_ID;
    protected $GA_KEY_FILE_LOCATION;

    public function __construct()
    {
        require_once 'config/config.php';

        if (isset($FB_PAGE_ID)) {
            $this->FB_PAGE_ID = $FB_PAGE_ID;
        }
        if (isset($FB_APP_ID)) {
            $this->FB_APP_ID = $FB_APP_ID;
        }
        if (isset($FB_APP_SECRET)) {
            $this->FB_APP_SECRET = $FB_APP_SECRET;
        }
        if (isset($FB_ACCESS_TOKEN)) {
            $this->FB_ACCESS_TOKEN = $FB_ACCESS_TOKEN;
        }

        if (isset($SERVICE_ACCOUNT_EMAIL)) {
            $this->GA_SERVICE_ACCOUNT_EMAIL = $SERVICE_ACCOUNT_EMAIL;
        }
        if (isset($VIEW_ID)) {
            $this->GA_VIEW_ID = $VIEW_ID;
        }
        if (isset($KEY_FILE_LOCATION)) {
            $this->GA_KEY_FILE_LOCATION = $KEY_FILE_LOCATION;
        }

        add_action('admin_menu', array($this, 'flux_analytics_menu'));
        add_action('admin_enqueue_scripts', array($this, 'add_highcharts'));

        // For Facebook SDK
        date_default_timezone_set('Asia/Seoul');
    }

    public function flux_analytics_menu()
    {
        add_menu_page('Flux Analytics', 'Flux Analytics', 'manage_options', $this->PAGE_SLUG, array(
            $this,
            'print_flux_analytics_page'
        ));
        add_submenu_page('flux_analytics', 'Flux Analytics Settings', 'Settings', 'manage_options', 'flux_analytics_settings', array(
            $this,
            'print_flux_analytics_settings_page'
        ));
    }

    public function print_flux_analytics_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $action = $_GET['action'];
        $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
        if ($action != 'analyze'):

            ?>
            <div class="wrap">
                <h1>발행된 포스트 목록(당일 발행 제외)</h1>
                <ul>
                    <?php

                    $args = array(
                        'post_type' => 'post',
                        'posts_per_page' => 50,
                        'post_status' => 'publish',
                        'date_query' => array(
                            'column' => 'post_date',
                            'before' => $now->format('Y-m-d')
                        )
                    );
                    $query = new WP_Query($args);
                    if ($query->have_posts()) :
                        while ($query->have_posts()) : $query->the_post();
                            ?>
                            <li>
                                <a href="<?php echo admin_url('admin.php') . '?page=' . $this->PAGE_SLUG . '&action=analyze&post_id=' . get_the_ID(); ?>">
                                    <?php echo the_time('Y-m-d') . ' ' . the_title(); ?>
                                </a>
                            </li>
                            <?php
                        endwhile;
                        wp_reset_postdata();
                    endif;
                    ?>
                </ul>
            </div>
            <?php
        else:
            // Composer.
            require_once __DIR__ . '/vendor/autoload.php';

            // Set Wordpress post data.
            global $post;
            $post_id = $_GET['post_id'];
            $post = get_post($post_id);
            setup_postdata($post);

            $published_at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $post->post_date, new DateTimeZone('Asia/Seoul'));
            $reaches = 0;
            $likes = 0;
            $comments = 0;
            $shares = 0;

            // Facebook
            $fb = $this->initializeFacebookSdk();
            $startDate = $published_at->modify('-1 day');
            $endDate = $published_at->modify('+1 day');
            $endPoint = '/' . $this->FB_PAGE_ID . '/feed?since=' . $startDate->format('Y-m-d') . '&until=' . $endDate->format('Y-m-d');
            try {
                $response = $fb->get($endPoint);
            } catch (Facebook\Exceptions\FacebookResponseException $e) {
                // When Graph returns an error
                echo 'Graph returned an error: ' . $e->getMessage();
                exit;
            } catch (Facebook\Exceptions\FacebookSDKException $e) {
                // When validation fails or other local issues
                echo 'Facebook SDK returned an error: ' . $e->getMessage();
                exit;
            }

            // Each Facebook post contains Wordpress url.
            // eg) http://ppss.kr/archives/$post_id
            foreach ($response->getGraphEdge()->all() as $item) {
                if (strpos($item->getField('message'), $post_id) !== false) {

                    $postResponse = $fb->get('/' . $item['id'] . '/insights/post_impressions,post_consumptions_by_type,post_stories_by_action_type?fields=values,name');

                    foreach ($postResponse->getGraphEdge()->all() as $insightItem) {
                        $name = $insightItem->getField('name');

                        switch ($name) {
                            case 'post_impressions':
                                $reaches = $insightItem->getField('values')->offsetGet(0)->getField('value');
                                break;

                            // TODO: What to do with clicks?
                            case 'post_consumptions_by_type':
                                break;

                            case 'post_stories_by_action_type':
                                $actions = $insightItem->getField('values')->offsetGet(0)->getField('value');
                                $likes = $actions->getField('like', 0);
                                $shares = $actions->getField('share', 0);
                                $comments = $actions->getField('comment', 0);
                                break;
                        }
                    }
                    break;
                }
            }

            // GA views
            $analytics = $this->initializeAnalytics();
            $resultViews = $analytics->data_ga->get(
                'ga:' . $this->GA_VIEW_ID,
                $published_at->format('Y-m-d'),
                'today',
                'ga:pageviews,ga:sessions,ga:avgSessionDuration',
                array(
                    'dimensions' => 'ga:date,ga:userAgeBracket,ga:userGender',
                    'filters' => 'ga:pagePath=~' . $post_id
                )
            );
            $rows = $resultViews->getRows();

            // GA scroll depths
            $resultScrollDepth = $analytics->data_ga->get(
                'ga:' . $this->GA_VIEW_ID,
                $published_at->format('Y-m-d'),
                'today',
                'ga:eventValue',
                array(
                    'dimensions' => 'ga:eventLabel',
                    'filters' => 'ga:pagePath=~' . $post_id
                )
            );
            $rowsScrollDepth = $resultScrollDepth->getRows();

            $totalPageviews = 0;
            $totalSessions = 0;
            $dates = array();
            $pageviews = array();
            $sessions = array();
            $totalSessionDurations = array();
            $avgSessionDurations = array();
            $scrollDepthsPercent = array();
            $scrollDepths = array();
            $ages = array();
            $agesByGenders = array('female' => array(), 'male' => array());

            // $row[0] : Date (y.m.d.)
            // $row[1] : Age (18-24, 25-34, ...)
            // $row[2] : Gender (female, male)
            // $row[3] : Pageviews
            // $row[4] : Sessions
            // $row[5] : Avg. Session Duration
            foreach ($rows as $row) {
                // Dates
                if (!in_array($row[0], $dates) && count($dates) < $this->MAX_DAYS_TO_DISPLAY) {
                    array_push($dates, $row[0]);
                }

                // Ages
                if (!in_array($row[1], $ages)) {
                    array_push($ages, $row[1]);
                }

                // Pageviews, Sessions by Date
                if (array_key_exists($row[0], $pageviews)) {
                    $pageviews[$row[0]] += $row[3];
                } else {
                    $pageviews[$row[0]] = $row[3];
                }
                if (array_key_exists($row[0], $sessions)) {
                    $sessions[$row[0]] += $row[4];
                } else {
                    $sessions[$row[0]] = $row[4];
                }

                // Total session durations by Date
                if (array_key_exists($row[0], $totalSessionDurations)) {
                    $totalSessionDurations[$row[0]] += $row[4] * $row[5];
                } else {
                    $totalSessionDurations[$row[0]] = $row[4] * $row[5];
                }

                // Age Brackets by Gender
                if (array_key_exists($row[1], $agesByGenders[$row[2]])) {
                    $agesByGenders[$row[2]][$row[1]] += $row[3];
                } else {
                    $agesByGenders[$row[2]][$row[1]] = $row[3];
                }

                $totalPageviews += $row[3];
                $totalSessions += $row[4];
            }

            // Do cumulative addition for pageviews and sessions.
            $pageviews = array_values($pageviews);
            $sessions = array_values($sessions);
            $totalSessionDurations = array_values($totalSessionDurations);
            foreach ($pageviews as $index => $pageview) {
                $avgSessionDurations[$index] = $totalSessionDurations[$index] / $sessions[$index];
                if ($index > 0) {
                    $pageviews[$index] += $pageviews[$index - 1];
                    $sessions[$index] += $sessions[$index - 1];
                }
            }

            // $rowScrollDepth[0] : Event Label (100%, 25%, ...)
            // $rowScrollDepth[1] : Event Value (based on pageviews)
            foreach ($rowsScrollDepth as $rowScrollDepth) {
                $scrollDepthsPercent[$rowScrollDepth[0]] = $rowScrollDepth[1];
            }
            $scrollDepths[0] = $totalPageviews - $scrollDepthsPercent['25%'];
            $scrollDepths[1] = $scrollDepthsPercent['25%'] - $scrollDepthsPercent['50%'];
            $scrollDepths[2] = $scrollDepthsPercent['50%'] - $scrollDepthsPercent['75%'];
            $scrollDepths[3] = $scrollDepthsPercent['75%'];
            ?>
            <div class="wrap flux-analytics">
                <h1><?php echo the_title() . '(' . $published_at->format('Y-m-d') . ')'; ?></h1>
                <div class="container">
                    <div class="summary-wrapper">
                        <div class="summary">
                            <div class="summary-header">PV(30일)</div>
                            <div class="summary-content"><?php echo number_format($totalPageviews); ?></div>
                        </div>
                        <div class="summary">
                            <div class="summary-header">UV(30일)</div>
                            <div class="summary-content"><?php echo number_format($totalSessions); ?></div>
                        </div>
                        <div class="summary">
                            <div class="summary-header">도달</div>
                            <div class="summary-content"><?php echo number_format($reaches); ?></div>
                        </div>
                        <div class="summary">
                            <div class="summary-header">공유</div>
                            <div class="summary-content"><?php echo number_format($shares); ?></div>
                        </div>
                        <div class="summary">
                            <div class="summary-header">댓글</div>
                            <div class="summary-content"><?php echo number_format($comments); ?></div>
                        </div>
                        <div class="summary">
                            <div class="summary-header">좋아요</div>
                            <div class="summary-content"><?php echo number_format($likes); ?></div>
                        </div>
                    </div>
                    <hr class="separation"/>

                    <div id="views" style="height: 400px; margin: 0 auto"></div>
                    <div id="ages-by-genders" style="height: 300px; margin: 0 auto"></div>
                    <div id="genders" style="height: 300px; margin: 0 auto"></div>
                    <div id="ages" style="height: 300px; margin: 0 auto"></div>
                    <div id="scroll-depth" style="height: 300px; margin: 0 auto"></div>
                </div>
            </div>
            <script>
                var lineChartCategories = [
                    <?php
                    foreach ($dates as $index => $date) {
                        if ($index == $this->MAX_DAYS_TO_DISPLAY) {
                            break;
                        }
                        echo $date . ', ';
                    }
                    ?>
                ];

                var lineChartSeries = [{
                    name: "PV",
                    type: "column",
                    yAxis: 0,
                    data: [
                        <?php
                        foreach ($pageviews as $index => $pageview) {
                            if ($index == $this->MAX_DAYS_TO_DISPLAY) {
                                break;
                            }
                            echo $pageview . ', ';
                        }
                        ?>
                    ]
                }, {
                    name: "UV",
                    type: "column",
                    yAxis: 0,
                    data: [
                        <?php
                        foreach ($sessions as $index => $session) {
                            if ($index == $this->MAX_DAYS_TO_DISPLAY) {
                                break;
                            }
                            echo $session . ', ';
                        }
                        ?>
                    ]
                }, {
                    name: "체류",
                    type: "spline",
                    yAxis: 1,
                    data: [
                        <?php
                        foreach ($avgSessionDurations as $index => $avgSessionDuration) {
                            if ($index == $this->MAX_DAYS_TO_DISPLAY) {
                                break;
                            }
                            echo $avgSessionDuration . ', ';
                        }
                        ?>
                    ]
                }];

                var scrollDepthSeries = [{
                    name: '스크롤 뎁스',
                    data: [
                        <?php
                        $scrollDepthsCount = count($scrollDepths);
                        $offset = 100 / $scrollDepthsCount; // 25
                        foreach ($scrollDepths as $index => $scrollDepth) {
                            echo '{';
                            echo 'name: "' . $index * $offset . '-' . ($index + 1) * $offset . '%",';
                            echo 'y: ' . $scrollDepth;
                            if ($index != $scrollDepthsCount - 1) {
                                echo '},';
                            } else {
                                echo '}';
                            }
                        }
                        ?>
                    ]
                }];

                var ageCategories = [
                    <?php
                    foreach ($ages as $age) {
                        echo '"' . $age . '",';
                    }
                    ?>
                ];

                var totalPageviews = <?php echo $totalPageviews; ?>;

                var ageSeries = [
                    <?php
                    foreach ($agesByGenders as $gender => $agesByGender) {
                        echo '{name: "' . $gender . '", data: [';
                        foreach ($agesByGender as $ageByGender) {
                            if ($gender == 'female') {
                                echo '-' . $ageByGender . ', ';
                            } else {
                                echo $ageByGender . ', ';
                            }
                        }

                        echo ']},';
                    }
                    ?>
                ];
            </script>
            <?php
        endif;
    }

    // TODO: Use DB instead of config file?
    public function print_flux_analytics_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap">
            <h1>애널리틱스 설정</h1>
        </div>
        <?php
    }

    public function add_highcharts($hook)
    {
        if ($hook != 'toplevel_page_flux_analytics') {
            return;
        }

        wp_enqueue_style('flux-analytics-style', plugin_dir_url(__FILE__) . 'css/style.css', array(), '0.0.1');
        wp_enqueue_script('highcharts', plugin_dir_url(__FILE__) . 'node_modules/highcharts/highcharts.js', array(), '4.2.5');
        wp_enqueue_script('flux-analytics', plugin_dir_url(__FILE__) . 'js/analytics.js', array(), '0.0.1', true);
    }

    /*
     * Creates and returns the Google Analytics service object.
     */
    private function initializeAnalytics()
    {
        // Create and configure a new client object.
        $client = new Google_Client();
        $client->setApplicationName("Hello Analytics Reporting");
        $analytics = new Google_Service_Analytics($client);

        // Read the generated client_secrets.p12 key.
        $key = file_get_contents($this->GA_KEY_FILE_LOCATION);
        $cred = new Google_Auth_AssertionCredentials(
            $this->GA_SERVICE_ACCOUNT_EMAIL,
            array(Google_Service_Analytics::ANALYTICS_READONLY),
            $key
        );
        $client->setAssertionCredentials($cred);
        if ($client->getAuth()->isAccessTokenExpired()) {
            try {
                $client->getAuth()->refreshTokenWithAssertion($cred);
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }

        return $analytics;
    }

    /*
     * Creates and returns the Facebook SDK object.
     */
    private function initializeFacebookSdk()
    {
        $fb = new Facebook\Facebook([
            'app_id' => $this->FB_APP_ID,
            'app_secret' => $this->FB_APP_SECRET,
            'default_graph_version' => 'v2.6',
            'default_access_token' => $this->FB_ACCESS_TOKEN,
        ]);
        return $fb;
    }
}

$analytics = new FluxAnalytics();

?>