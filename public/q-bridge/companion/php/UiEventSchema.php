<?php
declare(strict_types=1);

/**
 * UiEventSchema — allowlisted sanctum.companion.ui-event v1 validator (no framework).
 * Mirrors contracts/ui-event-v1/schema.json and the browser initiation parser.
 */
final class UiEventSchema
{
    public const SCHEMA = 'sanctum.companion.ui-event';
    public const VERSION = 1;
    public const TYPE_CANVAS_OPEN = 'canvas.open';
    public const MAX_TITLE = 120;
    public const EVENT_ID_MIN = 8;
    public const EVENT_ID_MAX = 128;

    /**
     * @param mixed $raw
     * @return array{ok:bool,reason?:string,event?:array}
     */
    public static function validate($raw): array
    {
        if (!is_array($raw)) {
            return ['ok' => false, 'reason' => 'not-object'];
        }
        if (($raw['schema'] ?? null) !== self::SCHEMA) {
            return ['ok' => false, 'reason' => 'schema'];
        }
        if ((int)($raw['version'] ?? 0) !== self::VERSION) {
            return ['ok' => false, 'reason' => 'version'];
        }
        $eventId = $raw['event_id'] ?? null;
        if (!is_string($eventId) || strlen($eventId) < self::EVENT_ID_MIN || strlen($eventId) > self::EVENT_ID_MAX) {
            return ['ok' => false, 'reason' => 'event_id'];
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $eventId)) {
            return ['ok' => false, 'reason' => 'event_id-charset'];
        }
        if (($raw['type'] ?? null) !== self::TYPE_CANVAS_OPEN) {
            return ['ok' => false, 'reason' => 'type'];
        }
        $payload = $raw['payload'] ?? null;
        if (!is_array($payload)) {
            return ['ok' => false, 'reason' => 'payload'];
        }
        foreach (array_keys($payload) as $key) {
            if ($key !== 'surface' && $key !== 'title') {
                return ['ok' => false, 'reason' => 'payload-key'];
            }
        }
        $surface = $payload['surface'] ?? 'primary';
        if ($surface !== 'primary') {
            return ['ok' => false, 'reason' => 'surface'];
        }
        $title = null;
        if (array_key_exists('title', $payload)) {
            if (!is_string($payload['title'])) {
                return ['ok' => false, 'reason' => 'title'];
            }
            if (strlen($payload['title']) > self::MAX_TITLE) {
                return ['ok' => false, 'reason' => 'title-oversized'];
            }
            if (preg_match('/[<>&]/', $payload['title']) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $payload['title'])) {
                return ['ok' => false, 'reason' => 'title'];
            }
            $trimmed = trim($payload['title']);
            $title = $trimmed === '' ? null : $trimmed;
        }

        $event = [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'event_id' => $eventId,
            'type' => self::TYPE_CANVAS_OPEN,
            'payload' => ['surface' => 'primary'],
        ];
        if ($title !== null) {
            $event['payload']['title'] = $title;
        }
        return ['ok' => true, 'event' => $event];
    }
}
