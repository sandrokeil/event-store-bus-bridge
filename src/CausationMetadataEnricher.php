<?php
/**
 * This file is part of the prooph/service-bus.
 * (c) 2014-%year% prooph software GmbH <contact@prooph.de>
 * (c) 2015-%year% Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStoreBusBridge;

use ArrayIterator;
use Prooph\Common\Event\ActionEvent;
use Prooph\Common\Event\ActionEventEmitter;
use Prooph\Common\Event\ActionEventListenerAggregate;
use Prooph\Common\Event\DetachAggregateHandlers;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\ActionEventEmitterAwareEventStore;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Metadata\MetadataEnricher;
use Prooph\EventStore\Plugin\Plugin;
use Prooph\EventStore\Stream;
use Prooph\EventStoreBusBridge\Exception\InvalidArgumentException;
use Prooph\ServiceBus\CommandBus;

final class CausationMetadataEnricher implements ActionEventListenerAggregate, MetadataEnricher, Plugin
{
    use DetachAggregateHandlers;

    /**
     * @var Message
     */
    private $currentCommand;

    public function setUp(EventStore $eventStore): void
    {
        if (! $eventStore instanceof ActionEventEmitterAwareEventStore) {
            throw new InvalidArgumentException(
                sprintf(
                    'EventStore must implement %s',
                    ActionEventEmitterAwareEventStore::class
                )
            );
        }

        $eventStore->getActionEventEmitter()->attachListener(
            ActionEventEmitterAwareEventStore::EVENT_APPEND_TO,
            function (ActionEvent $event): void {
                if (null === $this->currentCommand || ! $this->currentCommand instanceof Message) {
                    return;
                }

                $recordedEvents = $event->getParam('streamEvents');

                $enrichedRecordedEvents = [];

                foreach ($recordedEvents as $recordedEvent) {
                    $enrichedRecordedEvents[] = $this->enrich($recordedEvent);
                }

                $event->setParam('streamEvents', new ArrayIterator($enrichedRecordedEvents));
            },
            1000
        );

        $eventStore->getActionEventEmitter()->attachListener(
            ActionEventEmitterAwareEventStore::EVENT_CREATE,
            function (ActionEvent $event): void {
                if (null === $this->currentCommand || ! $this->currentCommand instanceof Message) {
                    return;
                }

                $stream = $event->getParam('stream');
                $recordedEvents = $stream->streamEvents();

                $enrichedRecordedEvents = [];

                foreach ($recordedEvents as $recordedEvent) {
                    $enrichedRecordedEvents[] = $this->enrich($recordedEvent);
                }

                $stream = new Stream(
                    $stream->streamName(),
                    new ArrayIterator($enrichedRecordedEvents),
                    $stream->metadata()
                );

                $event->setParam('stream', $stream);
            },
            1000
        );
    }

    public function attach(ActionEventEmitter $emitter): void
    {
        $this->trackHandler(
            $emitter->attachListener(
                CommandBus::EVENT_INVOKE_HANDLER,
                function (ActionEvent $event): void {
                    $this->currentCommand = $event->getParam(CommandBus::EVENT_PARAM_MESSAGE);
                },
                1000
            )
        );

        $this->trackHandler(
            $emitter->attachListener(
                CommandBus::EVENT_FINALIZE,
                function (ActionEvent $event): void {
                    $this->currentCommand = null;
                },
                1000
            )
        );
    }

    public function enrich(Message $message): Message
    {
        $message = $message->withAddedMetadata('_causation_id', $this->currentCommand->uuid()->toString());
        $message = $message->withAddedMetadata('_causation_name', $this->currentCommand->messageName());

        return $message;
    }
}