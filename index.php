<?php
require_once __DIR__ . '/vendor/autoload.php';

use Slim\Container;
use Slim\Http\Request;
use Slim\Http\Response;

use Http\Client\Common\HttpMethodsClient;
use Http\Client\Common\PluginClient;
use Http\Client\Common\Plugin\CachePlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\MessageFactoryDiscovery;
use Http\Discovery\StreamFactoryDiscovery;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Cache\Adapter\Filesystem\FilesystemCachePool;

use Imagine\Image\Box;
use Imagine\Image\Point;

// CONFIGURATION
$sources = [
	'himmag' => 'http://himmag.com',
	'iamkoo' => 'http://iamkoo.net',
];

$app = new Slim\App( [
	'settings'   => [
		'displayErrorDetails' => true,
		'sources'             => $sources,
		'cacheDir'            => __DIR__ . '/cache',
		'trustedProxies'      => [],
		'headers'             => [
			'cache-control' => 'public, max-age=31536000',
		],
		'sourceCacheOptions'  => [
			'default_ttl'    => 3600,
			'cache_lifetime' => 86400 * 5,
		],
	],
	'httpClient' => function ( Container $container ) {
		/** @var Request $request */
		$request    = $container->get( 'request' );
		$ipDetector = new RKA\Middleware\IpAddress( true, $container->settings['trustedProxies'] );
		$ipAddress  = $ipDetector( $request,
			$container->get( 'response' ),
			function ( $request, $response ) {
				return $request->getAttribute( 'ip_address' );
			} );

		$filesystemAdapter = new Local( $container->settings['cacheDir'] );
		$filesystem        = new Filesystem( $filesystemAdapter );
		$pool              = new FilesystemCachePool( $filesystem, '.' );
		$cachePlugin       = new CachePlugin(
			$pool,
			StreamFactoryDiscovery::find(),
			$container->settings['sourceCacheOptions']
		);

		$pluginClient = new PluginClient(
			HttpClientDiscovery::find(),
			[
				new HeaderSetPlugin( [
					'User-Agent' => $request->getHeaderLine( 'User-Agent' ),
					'Forwarded'  => 'for=' . $ipAddress,
				] ),
				$cachePlugin,
			]
		);

		$client = new HttpMethodsClient(
			$pluginClient,
			MessageFactoryDiscovery::find()
		);

		return $client;
	},
	'stream'     => function () {
		return StreamFactoryDiscovery::find();
	},
	'imagine'    => function ( Container $container ) {
		return new Imagine\Gd\Imagine();
	},
	'download'   => function ( Container $container ) {
		return new Download( $container->get( 'httpClient' ) );
	},
] );


$app->add( function ( Request $request, Response $response, $next ) {
	/** @var Response $response */
	$response = $next( $request, $response );
	if ( $response->hasHeader( 'x-image-src' ) ) {
		$src = $response->getHeaderLine( 'x-image-src' );
		/** @var Response $res */
		$res = call_user_func( $this->download, $src );

		$headerToClone = [ 'content-type', 'date' ];
		foreach ( $headerToClone as $name ) {
			if ( $res->hasHeader( $name ) ) {
				$response = $response->withHeader( $name, $res->getHeaderLine( $name ) );
			}
		}

		return $response
			->withoutHeader( 'x-image-src' )
			->withBody( $res->getBody() );

	}

	return $response;
} );

// Resize handler
$app->add( function ( Request $request, Response $response, $next ) {
	/** @var Response $response */
	$response = $next( $request, $response );
	if ( strpos( $response->getHeaderLine( 'content-type' ), 'image' ) === false ) {
		return $response;
	}

	$width    = intval( $request->getQueryParam( 'w', 0 ) );
	$height   = intval( $request->getQueryParam( 'h', 0 ) );
	$fit      = $request->getQueryParam( 'fit' );
	$crop     = trim( $request->getQueryParam( 'crop' ) );
	$quality  = intval( $request->getQueryParam( 'q', 75 ) );
	$imagine  = new Imagine\Gd\Imagine();

	if ( $width || $height ) {
		$image       = $imagine->read( $response->getBody()->detach() );
		$originalBox = $image->getSize();
		$box         = clone $originalBox;

		if ( $width > $originalBox->getWidth() ) {
			$width = $originalBox->getWidth();
		}

		if ( $height > $originalBox->getHeight() ) {
			$height = $originalBox->getHeight();
		}

		if ( $width && $height ) {
			$box = new Box( $width, $height );
		} elseif ( $width ) {
			$box = $originalBox->widen( $width );
		} elseif ( $height ) {
			$box = $originalBox->heighten( $height );
		}

		if ( $fit === 'crop' ) {
			// center
			$x = strpos( $crop, 'left' ) !== false ? 0 : ceil( ( $originalBox->getWidth() - $box->getWidth() ) / 2 );
			$y = strpos( $crop, 'top' ) !== false ? 0 : ceil( ( $originalBox->getHeight() - $box->getHeight() ) / 2 );

			if ( strpos( $crop, 'bottom' ) !== false ) {
				$y = $originalBox->getHeight() - $box->getHeight();
			}

			if ( strpos( $crop, 'right' ) !== false ) {
				$x = $originalBox->getWidth() - $box->getWidth();
			}

			$image->crop( new Point( $x, $y ), $box );
		}

		$image->resize( $box );

		$response = $response
			->withBody( $this->stream->createStream( $image->get( 'jpeg', [ 'quality' => $quality, 'jpeg_quality' => $quality ] ) ) )
			->withHeader( 'Last-Modified', gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
	}

	return $response;
} );

// Apply headers
$app->add( function ( Request $request, Response $response, $next ) {
	/** @var Response $response */
	$response = $next( $request, $response );
	if ( $response->getStatusCode() === 200 ) {
		foreach ( $this->settings['headers'] as $name => $value ) {
			$response = $response->withHeader( $name, $value );
		}
	}

	return $response;
} );

$app->get(
	sprintf( '/{endpoint:%s}/{path:.+}', implode( '|', array_keys( $sources ) ) ),
	function ( $request, Response $response, $args ) {
		return $response->withHeader(
			'x-image-src',
			wordpress_concatenator( $this->settings['sources'][ $args['endpoint'] ], $args['path'] ) );
	} );

$app->get(
	'/{uri:.*}',
	function ( Request $request, Response $response, $args ) {
		$uri = $args['uri'];
		if ( ! $uri ) {
			return $response->withStatus( 400 );
		}

		if ( 'http' !== substr( $uri, 0, 4 ) ) {
			$uri = ( $request->getQueryParam( 'secure' ) ? 'https' : 'http' ) . '://' . $uri;
		}

		return $response->withHeader( 'x-image-src', $uri );
	} );


$app->run();

function wordpress_concatenator( $endpoint, $path ) {
	return sprintf( '%s/wp-content/uploads/%s', $endpoint, $path );
}

class Download {
	/** @var  HttpMethodsClient */
	private $client;

	/**
	 * Download constructor.
	 *
	 * @param HttpMethodsClient $client
	 */
	public function __construct( HttpMethodsClient $client ) {
		$this->client = $client;
	}

	public function __invoke( $src ) {
		/** @var Response $res */
		$res = $this->client->get( $src );

		if ( $res->getStatusCode() === 302 ) {
			return $res->withStatus( 400, 'Bad Request: Redirect found' );
		}

		return $res;
	}
}
