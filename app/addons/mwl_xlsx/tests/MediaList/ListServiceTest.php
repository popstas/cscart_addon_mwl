<?php

namespace Tygh\Addons\MwlXlsx\Tests\MediaList;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\MwlXlsx\MediaList\ListRepository;
use Tygh\Addons\MwlXlsx\MediaList\ListService;
use Tygh\Registry;

class SessionStub
{
    /** @var string */
    private $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getID(): string
    {
        return $this->id;
    }
}

class ListServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Registry::set('addons.mwl_xlsx.max_list_items', 0);
    }

    public function testAddProductStopsAtLimit(): void
    {
        Registry::set('addons.mwl_xlsx.max_list_items', 2);

        $repository = $this->createMock(ListRepository::class);
        $repository->expects($this->once())
            ->method('countProducts')
            ->with(15)
            ->willReturn(2);
        $repository->expects($this->never())->method('productExists');
        $repository->expects($this->never())->method('insertProduct');
        $repository->expects($this->never())->method('touchList');

        $service = new ListService($repository, new SessionStub('sess-1'));

        $status = $service->addProduct(15, 101, ['size' => 'L'], 1);

        $this->assertSame(ListService::STATUS_LIMIT, $status);
    }

    public function testAddProductDetectsDuplicate(): void
    {
        $repository = $this->createMock(ListRepository::class);
        $repository->expects($this->once())
            ->method('countProducts')
            ->with(20)
            ->willReturn(1);
        $repository->expects($this->once())
            ->method('productExists')
            ->with(20, 55, serialize(['color' => 'red']))
            ->willReturn(true);
        $repository->expects($this->never())->method('insertProduct');
        $repository->expects($this->never())->method('touchList');

        Registry::set('addons.mwl_xlsx.max_list_items', 5);
        $service = new ListService($repository, new SessionStub('sess-2'));

        $status = $service->addProduct(20, 55, ['color' => 'red'], 1);

        $this->assertSame(ListService::STATUS_EXISTS, $status);
    }

    public function testAddProductInsertsAndTouchesList(): void
    {
        $repository = $this->createMock(ListRepository::class);
        $repository->expects($this->once())
            ->method('countProducts')
            ->with(5)
            ->willReturn(1);
        $repository->expects($this->once())
            ->method('productExists')
            ->with(5, 300, serialize([]))
            ->willReturn(false);
        $repository->expects($this->once())
            ->method('insertProduct')
            ->with(5, 300, serialize([]), 2);
        $repository->expects($this->once())
            ->method('touchList')
            ->with(5, $this->callback(function ($timestamp) {
                return is_string($timestamp) && $timestamp !== '';
            }));

        Registry::set('addons.mwl_xlsx.max_list_items', 10);
        $service = new ListService($repository, new SessionStub('sess-3'));

        $status = $service->addProduct(5, 300, [], 2);

        $this->assertSame(ListService::STATUS_ADDED, $status);
    }

    public function testGetMediaListsCountUsesSession(): void
    {
        $repository = $this->createMock(ListRepository::class);
        $repository->expects($this->once())
            ->method('countListsBySessionId')
            ->with('sess-4')
            ->willReturn(7);
        $repository->expects($this->never())->method('countListsByUserId');

        $service = new ListService($repository, new SessionStub('sess-4'));

        $this->assertSame(7, $service->getMediaListsCount([]));
    }
}
