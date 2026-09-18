<?php
declare(strict_types=1);

namespace KoutenDB;

require_once __DIR__ . '/TcpExceptions.php';

/** Wire identity metadata is carried from the server, never calculated here. @internal */
final class TcpId
{
    public static function fromFields(#[\SensitiveParameter] array $fields): KoutenId
    {
        if (count($fields) !== 6) {
            throw new ProtocolException('Invalid TCP ID field count');
        }
        foreach ([0 => '18446744073709551615', 1 => '4294967295', 2 => '4294967295'] as $i => $max) {
            if (!preg_match('/^(0|[1-9][0-9]*)$/D', $fields[$i]) || strlen($fields[$i]) > strlen($max) ||
                (strlen($fields[$i]) === strlen($max) && strcmp($fields[$i], $max) > 0)) {
                throw new ProtocolException('Invalid unsigned TCP ID field');
            }
        }
        foreach ([3, 4, 5] as $i) {
            if (!is_numeric($fields[$i]) || !is_finite((float)$fields[$i])) {
                throw new ProtocolException('Invalid TCP ID coordinate');
            }
        }
        if ((float)$fields[4] <= 0) {
            throw new ProtocolException('Invalid TCP ID period');
        }
        return new KoutenId($fields[0], (int)$fields[1], (int)$fields[2],
            (float)$fields[3], (float)$fields[4], (float)$fields[5]);
    }

    public static function fields(KoutenId $id): array
    {
        if ($id->period === null || $id->head === null) {
            throw new \InvalidArgumentException('TCP reads require the six-field ID returned by PUTR; four-field FFI IDs lack routing metadata');
        }
        $fields = [(string)$id->parent, (string)$id->epoch, (string)$id->seq];
        foreach ([$id->tWrite, $id->period, $id->head] as $number) {
            if (!is_finite($number)) {
                throw new \InvalidArgumentException('ID coordinates must be finite');
            }
            // Explicit precision is independent of the application serialize_precision setting.
            $fields[] = str_replace(',', '.', sprintf('%.17g', $number));
        }
        self::fromFields($fields);
        return $fields;
    }
}
