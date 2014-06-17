<?php

require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Silex\Provider\TranslationServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Silex\Provider\DoctrineServiceProvider;
use Igorw\Silex\ConfigServiceProvider;

class MyApplication extends Silex\Application
{
    use Silex\Application\TwigTrait;
    use Silex\Application\SecurityTrait;
    use Silex\Application\UrlGeneratorTrait;
}

function replace_tags($string, $tags, $force_lower = false)
{
    return preg_replace_callback('/\\{\\{([^{}]+)\}\\}/',
            function($matches) use ($tags)
            {
                $key = $force_lower ? strtolower($matches[1]) : $matches[1];
                return array_key_exists($key, $tags) 
                    ? $tags[$key] 
                    : '';
            }
            , $string);
}

function constant_strcmp($str1, $str2)
{
  $res = $str1 ^ $str2;
  $ret = strlen($str1) ^ strlen($str2); //not the same length, then fail ($ret != 0)
  for($i = strlen($res) - 1; $i >= 0; $i--) $ret |= ord($res[$i]);
  return !$ret;
}

/* setup */
$app = new MyApplication();

$app->register(new TranslationServiceProvider(), array(
    'translator.messages' => array(),
));
$app->register(new TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/templates',
));
$app->register(new UrlGeneratorServiceProvider());
$app->register(new DoctrineServiceProvider());

$env = getenv('APP_ENV') ?: 'prod';
$app->register(new ConfigServiceProvider(__DIR__."/config/base.php"));
$app->register(new ConfigServiceProvider(__DIR__."/config/$env.php"));

$app['twig']->addExtension(new Twig_Extension_Escaper(true));
$app['twig']->addExtension(new Twig_Extension_Debug());

if ($app['debug']) {
    ini_set('display_errors', 'On');
    error_reporting(E_ALL | E_STRICT);
}

/* Views */
$app->get('/you/{token}', function ($token) use($app) {
    list($user_id, $user) = $app['db']->fetchArray('SELECT id, name FROM users WHERE token=?', array($token));

    if (empty($user_id)) {
        return $app->abort(404);
    }

    $sql = "SELECT amount, created FROM donations WHERE user_id=? ".
           "ORDER BY created DESC";
    $donations = $app['db']->fetchAll($sql, array($user_id));

    $sql = "SELECT SUM(amount) FROM donations WHERE user_id=?";
    $current = $app['db']->fetchColumn($sql, array($user_id), 0);

    $ratio = round(($current / 750)*100);
    $ratio = min(100, $ratio);

    return $app->render('last-donations.twig', array(
        'user' => $user,
        'token' => $token,
        'donations' => $donations,
        'num_donations' => count($donations),
        'current' => $current,
        'ratio' => $ratio
    ));
})
->bind('individual');

$app->get('/donate/{token}', function ($token) use($app) {
    list($user_id) = $app['db']->fetchArray(
        'SELECT id FROM users WHERE token=?', array($token));

    if (empty($user_id)) {
        $app->abort(404, "Invalid donation link");
    }

    $url = replace_tags($app['betterplace.url'], array(
        'project_id' => $app['betterplace.project_id'],
        'client_id' => $app['betterplace.client_id'],
        'donation_amount' => 50,
        'client_reference' => $token
    ));

    return $app->redirect($url);
})
->bind('donate');

$app->get('/callback/{secret}', function (Request $request, $secret) use($app) {
    if (!constant_strcmp($secret, $app['betterplace.secret'])) {
        $app->abort(403, "Invalid secret");
    }

    $user_token = $request->query->get('donation_client_reference');
    $donation_token = $request->query->get('donation_token');
    $amount = intval($request->query->get('amount'));

    list($user_id) = $app['db']->fetchArray(
        'SELECT id FROM users WHERE token=?', array($user_token));

    if (empty($user_id)) {
        $app->abort(500);
    }

    $sql = 'INSERT INTO donations (transaction_id, user_id, amount) '.
           'VALUES (?, ?, ?)';

    $app['db']->executeUpdate($sql, array(
        $donation_token,
        $user_id,
        $amount
    ));

    return '';
});

$app->get('/leaderboard', function () use($app) {
    $sql = "SELECT u.name AS name, SUM(d.amount) AS amount ".
           "FROM users AS u LEFT JOIN (donations AS d) ON ".
           "(d.user_id=u.id) GROUP BY u.id ORDER BY amount DESC";

    $techbikers = $app['db']->query($sql)->fetchAll();

    array_walk($techbikers, function (&$value, $key) use($app) {
        $value['amount'] = $value['amount'] == NULL ? 0 : $value['amount'];
        $value['percentage'] = ($value['amount'] / $app['per_rider_amount']) * 100;
    });

    return $app->render('leaderboard.twig', array(
        'techbikers' => $techbikers
    ));
})
->bind('leaderboard');

$app->get('/', function() use($app) {
    $current = $app['db']->fetchColumn('SELECT SUM(amount) FROM donations', array(), 0);
    $users = $app['db']->fetchColumn('SELECT COUNT(id) FROM users', array(), 0);
    $donations = $app['db']->fetchAll('SELECT amount, created FROM donations');
    
    $total_amount = $app['per_rider_amount'] * intval($users);
    $ratio = $total_amount > 0 ? round(($current / $total_amount) * 100) : 0;
    $ratio = min(100, $ratio);

    return $app->render('last-donations.twig', array(
        'current' => $current,
        'ratio' => $ratio,
        'donations' => $donations
    ));
})
->bind('home');

$app->run();

?>