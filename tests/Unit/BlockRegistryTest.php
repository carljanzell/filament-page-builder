<?php

use CarlJanzell\FilamentPageBuilder\BlockRegistry;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\BannerBlock;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\HeadingBlock;
use CarlJanzell\FilamentPageBuilder\Tests\Fixtures\Blocks\RestrictedBlock;

beforeEach(function (): void {
    $this->registry = app(BlockRegistry::class);
});

it('keys registered blocks by their type', function (): void {
    expect($this->registry->all())
        ->toHaveKeys(['heading', 'banner', 'restricted'])
        ->and($this->registry->find('heading'))->toBe(HeadingBlock::class);
});

it('rejects a class that is not a page block', function (): void {
    expect(fn () => (new BlockRegistry)->register([stdClass::class]))
        ->toThrow(InvalidArgumentException::class);
});

it('separates registration from authorisation', function (): void {
    RestrictedBlock::$visible = false;

    expect($this->registry->has('restricted'))->toBeTrue()
        ->and($this->registry->isVisible('restricted'))->toBeFalse()
        ->and($this->registry->visible())->not->toHaveKey('restricted');

    RestrictedBlock::$visible = true;

    expect($this->registry->isVisible('restricted'))->toBeTrue()
        ->and($this->registry->visible())->toHaveKey('restricted');
});

it('treats an unknown type as absent rather than failing', function (): void {
    expect($this->registry->has('nope'))->toBeFalse()
        ->and($this->registry->find('nope'))->toBeNull()
        ->and($this->registry->isVisible('nope'))->toBeFalse()
        ->and($this->registry->view('nope'))->toBeNull()
        ->and($this->registry->fileFields('nope'))->toBe([]);
});

it('exposes the view and file fields a block declares', function (): void {
    expect($this->registry->view('banner'))->toBe(BannerBlock::view())
        ->and($this->registry->fileFields('banner'))->toBe(['image'])
        ->and($this->registry->fileFields('heading'))->toBe([]);
});
