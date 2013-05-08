<?php
/**
 * User: zach
 * Date: 5/1/13
 * Time: 11:41 AM
 */

namespace Elasticsearch;

use Elasticsearch\Common\Exceptions;
use Elasticsearch\Connections\CurlMultiConnection;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;

/**
 * Class Client
 *
 * @category Elasticsearch
 * @package  Elasticsearch
 * @author   Zachary Tong <zachary.tong@elasticsearch.com>
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache2
 * @link     http://elasticsearch.org
 */
class Client
{

    /**
     * Holds an array of host/port tuples for the cluster
     *
     * @var array
     */
    protected $hosts;

    /**
     * @var Transport
     */
    protected $transport;

    /**
     * @var \Pimple
     */
    protected $params;

    /**
     * @var array
     */
    protected $paramDefaults = array(
                                'connectionClass'       => '\Elasticsearch\Connections\CurlMultiConnection',
                                'connectionPoolClass'   => '\Elasticsearch\ConnectionPool\ConnectionPool',
                                'selectorClass'         => '\Elasticsearch\ConnectionPool\Selectors\RoundRobinSelector',
                                'deadPoolClass'         => '\Elasticsearch\ConnectionPool\DeadPool',
                                'snifferClass'          => '\Elasticsearch\Sniffers\Sniffer',
                                'serializer'            => 'JSONSerializer',
                                'sniffOnStart'          => false,
                                'sniffAfterRequests'    => false,
                                'sniffOnConnectionFail' => false,
                                'randomizeHosts'        => true,
                                'maxRetries'            => 3,
                                'deadTimeout'           => 60,
                                'connectionParams'      => array(),
                                'logObject'             => null,
                                'logPath'               => 'elasticsearch.log',
                                'logLevel'              => Logger::WARNING,
                               );

    /**
     * Client constructor
     *
     * @param null|array $hosts  Array of Hosts to connect to
     * @param array      $params Array of injectable parameters
     *
     * @throws Common\Exceptions\InvalidArgumentException
     */
    public function __construct($hosts=null, $params=array())
    {
        if (isset($hosts) === false) {
            $this->hosts[] = array('host' => 'localhost');
        } else {
            if (is_array($hosts) === false) {
                throw new Exceptions\InvalidArgumentException('Hosts parameter must be an array of strings');
            }

            foreach ($hosts as $host) {
                if (strpos($host, ':') !== false) {
                    $host = explode(':', $host);
                    if ($host[1] === '' || is_numeric($host[1]) === false) {
                        throw new Exceptions\InvalidArgumentException('Port must be a valid integer');
                    }

                    $this->hosts[] = array(
                                      'host' => $host[0],
                                      'port' => (int) $host[1],
                                     );
                } else {
                    $this->hosts[] = array('host' => $host);
                }
            }
        }//end if

        $this->setParams($this->hosts, $params);
        $this->setLogging();
        $this->transport = $this->params['transport'];

    }//end __construct()


    /**
     * Sets up the DIC parameter object
     *
     * Merges user-specified parameters into the default list, then
     * builds a DIC to house all the information
     *
     * @param array $hosts  Array of hosts
     * @param array $params Array of user settings
     *
     * @throws Common\Exceptions\InvalidArgumentException
     * @throws Common\Exceptions\UnexpectedValueException
     * @return void
     */
    private function setParams($hosts, $params)
    {
        if (isset($params) === false || is_array($params) !== true) {
            throw new Exceptions\InvalidArgumentException('Parameters must be an array');
        }

        // Verify that all provided parameters are 'white-listed'.
        foreach ($params as $key => $value) {
            if (array_key_exists($key, $this->paramDefaults) === false) {
                throw new Exceptions\UnexpectedValueException($key.' is not a recognized parameter');
            }
        }

        $this->params = new \Pimple();

        $params = array_merge($this->paramDefaults, $params);

        foreach ($params as $key => $value) {
            $this->params[$key] = $value;
        }

        // Only used by some connections - won't be instantiated until used.
        $this->params['curlMultiHandle'] = $this->params->share(
            function () {
                return curl_multi_init();
            }
        );

        // This will inject a shared object into all connections.
        // Allows users to inject shared resources, similar to the multi-handle
        // shared above (but in their own code).
        $this->params['connectionParamsShared'] = $this->params->share(
            function ($dicParams) {

                $connectionParams = $dicParams['connectionParams'];

                // Multihandle connections need a "static", shared curl multihandle.
                if ($dicParams['connectionClass'] === '\Elasticsearch\Connections\CurlMultiConnection') {
                    $connectionParams = array_merge(
                        $connectionParams,
                        array('curlMultiHandle' => $dicParams['curlMultiHandle'])
                    );
                }

                return $connectionParams;
            }
        );

        $this->params['connection'] = function ($dicParams) {
            return function ($host, $port=null) use ($dicParams) {
                return new $dicParams['connectionClass'](
                    $host,
                    $port,
                    $dicParams['connectionParamsShared']
                );
            };
        };

        $this->params['selector'] = function ($dicParams) {
            return new $dicParams['selectorClass']();
        };

        $this->params['deadPool'] = function ($dicParams) {
            return new $dicParams['deadPoolClass']($dicParams['deadTimeout']);
        };

        // Share the ConnectionPool class as we only want one floating around.
        $this->params['connectionPool'] = $this->params->share(
            function ($dicParams) {
                return function ($connections) use ($dicParams) {
                    return new $dicParams['connectionPoolClass'](
                        $connections,
                        $dicParams['selector'],
                        $dicParams['deadPool'],
                        $dicParams['randomizeHosts']);
                };
            }
        );

        // Share the Transport class as we only want one floating around.
        $this->params['transport'] = $this->params->share(
            function ($dicParams) use ($hosts) {
                return new Transport($hosts, $dicParams, $dicParams['logObject']);
            }
        );

        $this->params['sniffer'] = function ($dicParams) {
            return new $dicParams['snifferClass']();
        };

    }//end setParams()


    private function setLogging()
    {
        // If no user-specified logger, provide a default file logger.
        if ($this->params['logObject'] === null) {
            $log     = new Logger('log');
            $handler = new StreamHandler(
                $this->params['logPath'],
                $this->params['logLevel']
            );
            $processor = new IntrospectionProcessor();

            $log->pushHandler($handler);
            $log->pushProcessor($processor);

            $this->params['logObject'] = $log;
        }

    }//end setLogging()


}//end class
