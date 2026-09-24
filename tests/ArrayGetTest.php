<?php

declare(strict_types=1);

namespace BoA\Tests;

use BoA\Core\Utils\Utils;
use PHPUnit\Framework\TestCase;

final class ArrayGetTest extends TestCase
{
    public function testArrayGetReturnsValueWhenKeyExists(): void
    {
        $this->assertSame('v', Utils::arrayGet(['k' => 'v'], 'k'));
        $this->assertSame(0, Utils::arrayGet(['k' => 0], 'k', 99));
        $this->assertNull(Utils::arrayGet(['k' => null], 'k', 'default'));
    }

    public function testArrayGetReturnsDefaultWhenKeyMissing(): void
    {
        $this->assertNull(Utils::arrayGet(['a' => 1], 'missing'));
        $this->assertSame('x', Utils::arrayGet(['a' => 1], 'missing', 'x'));
        $this->assertSame([], Utils::arrayGet(null, 'k', []));
        $this->assertSame([], Utils::arrayGet('not-array', 'k', []));
    }

    public function testArrayGetSupportsArrayAccess(): void
    {
        $obj = new \ArrayObject(['a' => 2]);
        $this->assertSame(2, Utils::arrayGet($obj, 'a'));
        $this->assertSame('d', Utils::arrayGet($obj, 'missing', 'd'));
    }
}
