<?php

namespace Tests\Unit;

use App\Services\ParentChildIdentityMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParentChildIdentityMatcherTest extends TestCase
{
    #[DataProvider('surnameCases')]
    public function test_it_compares_only_the_first_last_name(
        ?string $parentName,
        ?string $memberName,
        bool $expected,
    ): void {
        $matcher = new ParentChildIdentityMatcher;

        $this->assertSame($expected, $matcher->lastNameMatches($parentName, $memberName));
    }

    public static function surnameCases(): array
    {
        return [
            'same first surname and different second surname' => [
                'María Santos Rivera',
                'Elena Santos López',
                true,
            ],
            'same second surname only' => [
                'María Santos Rivera',
                'Elena Rivera Santos',
                false,
            ],
            'single surname names' => [
                'María Santos',
                'Elena Santos',
                true,
            ],
            'surname particles are ignored' => [
                'María de los Santos Rivera',
                'Elena Santos López',
                true,
            ],
            'case and accents are normalized' => [
                'MARÍA Muñoz Rivera',
                'Elena Munoz López',
                true,
            ],
            'incomplete parent name' => [
                'Santos',
                'Elena Santos López',
                false,
            ],
        ];
    }
}
