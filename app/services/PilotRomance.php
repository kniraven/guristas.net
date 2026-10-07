<?php
declare(strict_types=1);
require_once __DIR__ . '/PilotRecordStore.php';
function eve_romance_chapters(): array
{
    return [
        ['title' => 'A private frequency', 'text' => 'Ren Vey, an adult Guristas courier, leaves you a message: “Most pilots ask what I can get them. You asked whether I made it home. Was that a line, or did you mean it?”',
            'choices' => ['honest' => ['I meant it. Tell me about your day.', 25], 'playful' => ['A good line can also be true.', 20], 'reserved' => ['Let’s keep this professional for now.', 5]]],
        ['title' => 'Between contracts', 'text' => 'Ren invites you to a quiet booth after a difficult delivery. “No contracts tonight. Tell me something you choose for yourself.”',
            'choices' => ['open' => ['I want somewhere—and someone—to come home to.', 35], 'adventure' => ['One day I want us to take a trip with no cargo manifest.', 25], 'guarded' => ['I’m still figuring that out.', 10]]],
        ['title' => 'A place beside you', 'text' => 'Ren turns off the comms. “I like who I am around you. Do you want to see where this goes, away from the contracts?”',
            'choices' => ['together' => ['Yes. At our own pace, together.', 40], 'slow' => ['Yes, but I need us to take it slowly.', 30], 'friends' => ['I value you. I’d rather stay friends.', 0]]],
    ];
}
function eve_romance_transition(array $record, string $action, ?string $choice = null, ?int $chapter = null): array
{
    $story = &$record['romance'];
    if ($action === 'romance_start') { $story['enabled'] = true; return $record; }
    if ($action === 'romance_pause') { $story['enabled'] = false; return $record; }
    $chapters = eve_romance_chapters();
    if ($action !== 'romance_choice' || !$story['enabled'] || $chapter !== $story['chapter'] || !isset($chapters[$chapter]['choices'][$choice ?? ''])) throw new InvalidArgumentException('This story choice is no longer available. Reload the page.');
    $story['choices'][] = $choice;
    $story['affinity'] = min(100, $story['affinity'] + $chapters[$chapter]['choices'][$choice][1]);
    $story['chapter']++;
    return $record;
}
