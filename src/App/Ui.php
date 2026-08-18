<?php

declare(strict_types=1);

namespace Kanon\App;

/** Presentation rules shared by every screen: chip order and Czech group names. */
final class Ui
{
    public const GROUP_ORDER = ['obdobi', 'podobdobi', 'narodni', 'forma', 'special'];

    private const LABELS = [
        'obdobi'    => 'období',
        'podobdobi' => 'literární období',
        'narodni'   => 'národní literatura',
        'forma'     => 'literární forma',
        'special'   => 'zvláštní',
    ];

    public static function groupLabel(string $group): string
    {
        return self::LABELS[$group] ?? $group;
    }

    /**
     * @param  array<string, list<array{code: string, label: string, verified: bool}>> $tags
     * @return list<array{group: string, code: string, label: string, verified: bool}>
     */
    public static function orderedTags(array $tags): array
    {
        $ordered = [];

        foreach (self::GROUP_ORDER as $group) {
            foreach ($tags[$group] ?? [] as $tag) {
                $ordered[] = [
                    'group'    => $group,
                    'code'     => $tag['code'],
                    'label'    => $tag['label'],
                    'verified' => $tag['verified'],
                ];
            }
        }

        return $ordered;
    }
}
