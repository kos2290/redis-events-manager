<?php

namespace KonstantinDmitrienko\App\Model;

use JsonException;
use KonstantinDmitrienko\App\App;
use KonstantinDmitrienko\App\Interfaces\StorageInterface;
use Predis\Client;

/**
 * Redis Model
 */
class Redis implements StorageInterface
{
    /**
     * @var Client
     */
    protected Client $redis;

    /**
     * Constructor
     */
    public function __construct()
    {
        $config = App::getConfig();

        $this->redis = new Client([
            'scheme'   => 'tcp',
            'host'     => $config['redis']['host'],
            'port'     => $config['redis']['port'],
            'password' => $config['redis']['pass']
        ]);
    }

    /**
     * @param Event $event
     *
     * @return bool
     */
    public function saveEvent(Event $event): bool
    {
        $this->redis->sadd($event::KEY, (array) $event->getId());
        $this->redis->hset($event->getId(), $event::NAME, $event->getName());
        $this->redis->hset($event->getId(), $event::PRIORITY, $event->getPriority());
        $this->redis->hset($event->getId(), $event::CONDITIONS, $event->getConditions());

        return true;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function deleteEvents(string $key): bool
    {
        if (!($events = $this->redis->smembers($key))) {
            return false;
        }

        foreach ($events as $eventID) {
            $this->redis->hdel($eventID, [Event::NAME, Event::PRIORITY, Event::CONDITIONS]);
        }

        $this->redis->srem($key, $events);

        return true;
    }

    /**
     * @throws JsonException
     */
    public function getEvent(array $params): array
    {
        $event = [];
        if (!($events = $this->redis->smembers(Event::KEY))) {
            return $event;
        }

        $relatedEvents = [];
        foreach ($events as $eventID) {
            $conditions = json_decode($this->redis->hget($eventID, Event::CONDITIONS), true, 512, JSON_THROW_ON_ERROR);

            $countSameConditions = 0;
            foreach ($conditions as $parameterKey => $parameterValue) {
                if ($params[$parameterKey] === $parameterValue) {
                    $countSameConditions++;
                }
            }

            if (count($conditions) === $countSameConditions) {
                $relatedEvents[$eventID] = [
                    Event::NAME       => $this->redis->hget($eventID, Event::NAME),
                    Event::PRIORITY   => $this->redis->hget($eventID, Event::PRIORITY),
                    Event::CONDITIONS => $this->redis->hget($eventID, Event::CONDITIONS),
                ];
            }
        }

        if ($relatedEvents) {
            usort($relatedEvents, static fn($a, $b) => $a[Event::PRIORITY] < $b[Event::PRIORITY]);
            $event = reset($relatedEvents);
        }

        return $event;
    }
}
