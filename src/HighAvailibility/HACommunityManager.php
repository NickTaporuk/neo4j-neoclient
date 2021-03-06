<?php

namespace Neoxygen\NeoClient\HighAvailibility;

use Neoxygen\NeoClient\Connection\ConnectionManager,
    Neoxygen\NeoClient\Event\HttpExceptionEvent,
    Neoxygen\NeoClient\Event\PostRequestSendEvent,
    Neoxygen\NeoClient\Event\HttpClientPreSendRequestEvent,
    Neoxygen\NeoClient\NeoClientEvents,
    Neoxygen\NeoClient\HttpClient\GuzzleHttpClient,
    Neoxygen\NeoClient\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class HACommunityManager implements EventSubscriberInterface
{
    protected $connectionManager;

    protected $logger;

    protected $httpClient;

    protected $slavesUsed = [];

    protected $writeReplicationUsed = [];

    protected $masterUsed;

    protected $fails = [];

    public static function getSubscribedEvents()
    {
        return array(
            NeoClientEvents::NEO_HTTP_EXCEPTION => array(
                'onRequestException', 50
            ),
            NeoClientEvents::NEO_POST_REQUEST_SEND => array(
                'onSuccessfulRequest', 50
            ),
            NeoClientEvents::NEO_PRE_REQUEST_SEND => array(
                'onPreSend', 50
            )
        );
    }

    public function __construct(ConnectionManager $connectionManager, GuzzleHttpClient $httpClient, LoggerInterface $logger)
    {
        $this->connectionManager = $connectionManager;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function onRequestException(HttpExceptionEvent $event)
    {
        $request = $event->getRequest();
        $this->fails[$request->getConnection()] = !isset($this->fails[$request->getConnection()]) ? 1 : $this->fails[$request->getConnection()] +1;
        if ($request->hasQueryMode()) {
            if ($request->getQueryMode() == 'READ') {
                $this->slavesUsed[] = $request->getConnection();
                if ($this->connectionManager->hasNextSlave($this->slavesUsed)) {
                    $next = $this->connectionManager->getNextSlave($this->slavesUsed);
                    Client::log('warning', sprintf('Connection "%s" unreacheable, using "%s"', $request->getConnection(), $next));
                    $request->setInfoFromConnection($this->connectionManager->getConnection($next));
                    $event->stopPropagation();
                } elseif (null === $this->masterUsed) {
                    $master = $this->connectionManager->getMasterConnection();
                    Client::log('warning', sprintf('Connection "%s" unreacheable, using "%s"', $request->getConnection(), $master->getAlias()));
                    $this->masterUsed = true;
                    $request->setInfoFromConnection($master);
                    $event->stopPropagation();
                }
            }
        }
    }

    public function onSuccessfulRequest(PostRequestSendEvent $event)
    {
        $request = $event->getRequest();
        $this->fails[$request->getConnection()] = null;
        $this->slavesUsed = [];
        $this->masterUsed = null;
        if ($request->hasQueryMode()) {
            if ($request->getQueryMode() === 'WRITE') {
                $master = $this->connectionManager->getMasterConnection()->getAlias();
                if ($request->getConnection() === $master) {
                    $slaves = $this->connectionManager->getSlaves();
                    $slave = current($slaves);
                    $this->writeReplicationUsed[] = $slave;
                    Client::log('debug', sprintf('Performing write replication on connection "%s"', $slave));
                    $request->setInfoFromConnection($this->connectionManager->getConnection($slave));
                    $event->stopPropagation();
                } elseif ($this->connectionManager->hasNextSlave($this->writeReplicationUsed)) {
                    $next = $this->connectionManager->getNextSlave($this->writeReplicationUsed);
                    $nc = $this->connectionManager->getConnection($next);
                    Client::log('debug', sprintf('Performing write replication on connection "%s"', $next));
                    $request->setInfoFromConnection($nc);
                    $event->stopPropagation();
                } elseif (null !== $this->masterUsed && !$this->connectionManager->hasNextSlave($this->masterUsed) && $request->getConnection() !== $master) {
                    $this->masterUsed = [];
                    Client::log('debug', 'Replication terminated');
                }
            }
        }
    }

    public function onPreSend(HttpClientPreSendRequestEvent $event)
    {
        $request = $event->getRequest();
        $conn = $request->getConnection();
        if (isset($this->fails[$conn]) && $this->fails[$conn] >= 5) {
            if ($request->hasQueryMode()) {
                if ($request->getQueryMode() === 'READ') {
                    if ($this->connectionManager->hasNextSlave([$conn])) {
                        $next = $this->connectionManager->getNextSlave([$conn]);
                        $request->setInfoFromConnection($this->connectionManager->getConnection($next));
                    }
                }
            }
        }
    }
}
