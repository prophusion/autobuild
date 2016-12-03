<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use \Ulrichsg\Getopt\Getopt;
use \Ulrichsg\Getopt\Option;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Promise;
use Icecave\SemVer;

$getopt = new Getopt(
  [
    (new Option('u', 'upload-script-dir', Getopt::REQUIRED_ARGUMENT))
      ->setDescription('Path to the upload script directory to mount in the build container at /upload')
      ->setValidation(function ($v) {
        return is_dir($v) && is_executable("$v/script");
      }),
    (new Option('m', 'max-checks', Getopt::REQUIRED_ARGUMENT))
      ->setDefaultValue(15)
      ->setDescription('Max number of past releases to verify presence of on prophusion.org. Default "15".')
      ->setValidation(function($v) { return is_int($v + 0) && $v + 0 > 0; }),
    (new Option('d', 'debug', Getopt::NO_ARGUMENT))
      ->setDescription('Print verbose information about what it is doing.'),
    (new Option('h', 'help', Getopt::NO_ARGUMENT))
  ]
);

try {
  $getopt->parse();
  if ($getopt['h']) {
    echo $getopt->getHelpText();
    exit(0);
  }
  if (empty($getopt['upload-script-dir'])) {
    throw new UnexpectedValueException('Must provide --upload-script-dir. See -h for help.');
  }
} catch (\UnexpectedValueException $e) {
  fwrite(STDERR, $e->getMessage() . "\n");
  exit(1);
}

$missing = array_merge(checkMajorVersion(5), checkMajorVersion(7));

if (count($missing)) {
  echo "Found the following releases that need to be built:\n";
  echo implode(', ', $missing) . "\n";

  if (count($missing) > 20) {
    fwrite(STDERR, "More than 20 releases to build? What's going on here?!?");
    fwrite(STDERR, "Aborting.");
    exit(1);
  }
  foreach ($missing as $release_to_build) {
    buildRelease($release_to_build);
  }
}

/**
 * @param int $major
 */
function checkMajorVersion($major) {
  $missing_versions = [];

  $client = new Client();
  /**
   * @var Getopt $getopt
   */
  $getopt = $GLOBALS['getopt'];
  $max = $getopt['max-checks'];
  $url = "https://secure.php.net/releases/?json&max=$max&version=$major";
  if ($getopt['d']) {
    echo "Hitting $url...\n";
  }
  $response = $client->request('GET', $url);

  $response_ok = $response->getStatusCode() === 200;
  if ($response_ok) {
    $release_data = json_decode($response->getBody(), TRUE);
    if ($release_data === NULL) {
      if ($getopt['d']) {
        fwrite(STDERR, "Body not JSON.\n");
        fwrite(STDERR, "Received:\n");
        fwrite(STDERR, $response->getBody() . "\n");
      }
      $response_ok = FALSE;
    }
  }

  if (! $response_ok) {
    fwrite(STDERR, "Unexpected response from php.net release json\n");
    fwrite(STDERR, "URL: $url\n");
    fwrite(STDERR, "Response code: " . $response->getStatusCode() . "\n");
    exit(1);
  }

  $releases = array_keys($release_data);
  if ($getopt['d']) {
    echo "Will check the following releases:\n";
    echo implode(', ', $releases) . "\n";
  }

  $sem_comparison = new SemVer\Comparator();
  foreach ($releases as $release) {
    // we don't do < 5.3.9 currently.
    if ($sem_comparison->compare(SemVer\Version::parse($release), SemVer\Version::parse('5.3.9')) < 0) {
      continue;
    }
    $variants = [''];
    // apache builds for 5.3 don't work via php-build at present
    if (strncmp('5.3.', $release, 4) !== 0) {
      $variants = ['', '-apache'];
    }
    $requests = [];
    foreach ($variants as $variant) {
      $requests[$variant] = $client->sendAsync(new Request('HEAD',
        "https://prophusion.org/$release$variant"),
        ['allow_redirects' => FALSE]
      );
    }

    $results = Promise\settle($requests)->wait();

    foreach ($results as $variant => $response) {
      /**
       * @var \GuzzleHttp\Psr7\Response $response
       */
      $release_variant = "$release$variant";
      // Have to do considerable BS to get at the Response if it didn't succeed.
      if ($response['state'] === 'rejected' && $response['reason'] instanceof \GuzzleHttp\Exception\ClientException) {
        /**
         * @var \GuzzleHttp\Exception\ClientException $reason
         */
        $reason = $response['reason'];
        $response = $reason->getResponse();
      } else {
        $response = $response['value'];
      }

      switch ($response->getStatusCode()) {
        case 302:
          if ($getopt['d']) {
            echo "$release_variant is present\n";
          }
          break;
        case 404:
          if ($getopt['d']) {
            echo "$release_variant is MISSING!\n";
          }
          $missing_versions[] = $release;
          break;
        default:
          fwrite(STDERR, "Unexpected response from prophusion.org checking $release\n");
          fwrite(STDERR, "Response code " . $response->getStatusCode() . "\n");
          break;
      }
    }
  }
  return array_unique($missing_versions);
}

/**
 * @param string $release
 */
function buildRelease($release) {
  /**
   * @var Getopt $getopt
   */
  static $docker_restarted = FALSE;
  if (! $docker_restarted) {
    // Docker containers seem to lose connectivity if docker has been running for awhile...
    passthru("/usr/sbin/service docker restart");
    $docker_restarted = TRUE;
  }
  $getopt = $GLOBALS['getopt'];
  $vol = $getopt['upload-script-dir'];
  passthru("docker run --name prophusion-autobuilder -v $vol:/upload prophusion/prophusion-builder $release ; docker rm prophusion-autobuilder");
}

