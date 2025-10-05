<?php

namespace Tygh\Addons\MwlXlsx\Tests\Security;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\MwlXlsx\Security\AccessService;
use Tygh\Registry;

class AccessServiceTest extends TestCase
{
    private AccessService $service;

    protected function setUp(): void
    {
        $this->service = new AccessService();

        Registry::set('addons.mwl_xlsx.allowed_usergroups', '');
        Registry::set('addons.mwl_xlsx.authorized_usergroups', '');
        Registry::set('addons.mwl_xlsx.hide_price_for_guests', 'N');
    }

    /**
     * @dataProvider usergroupAccessProvider
     */
    public function testCheckUsergroupAccess(array $auth, $setting_value, bool $expected): void
    {
        Registry::set('addons.mwl_xlsx.test_setting', $setting_value);

        $actual = $this->service->checkUsergroupAccess($auth, 'test_setting');

        $this->assertSame($expected, $actual);
    }

    public function usergroupAccessProvider(): array
    {
        return [
            'administrators always allowed' => [
                ['user_type' => 'A'],
                '1,2,3',
                true,
            ],
            'empty setting allows everyone' => [
                ['user_type' => 'C', 'usergroup_ids' => [5]],
                '',
                true,
            ],
            'matching usergroup passes' => [
                ['user_type' => 'C', 'usergroup_ids' => [3, 5]],
                '2,5',
                true,
            ],
            'no intersection fails' => [
                ['user_type' => 'C', 'usergroup_ids' => [7]],
                '2,5',
                false,
            ],
            'array setting supported' => [
                ['user_type' => 'C', 'usergroup_ids' => [10]],
                [8, 10, 12],
                true,
            ],
        ];
    }

    /**
     * @dataProvider canAccessListsProvider
     */
    public function testCanAccessLists(array $auth, $setting_value, bool $expected): void
    {
        Registry::set('addons.mwl_xlsx.allowed_usergroups', $setting_value);

        $this->assertSame($expected, $this->service->canAccessLists($auth));
    }

    public function canAccessListsProvider(): array
    {
        return [
            'admin can access lists' => [
                ['user_type' => 'A'],
                '2',
                true,
            ],
            'allowed group' => [
                ['user_type' => 'C', 'usergroup_ids' => [2]],
                '2,4',
                true,
            ],
            'not allowed group' => [
                ['user_type' => 'C', 'usergroup_ids' => [3]],
                '2,4',
                false,
            ],
            'empty setting allows all' => [
                ['user_type' => 'C', 'usergroup_ids' => [3]],
                '',
                true,
            ],
        ];
    }

    /**
     * @dataProvider canViewPriceProvider
     */
    public function testCanViewPrice(array $auth, string $hide_for_guests, $authorized_usergroups, bool $expected): void
    {
        Registry::set('addons.mwl_xlsx.hide_price_for_guests', $hide_for_guests);
        Registry::set('addons.mwl_xlsx.authorized_usergroups', $authorized_usergroups);

        $this->assertSame($expected, $this->service->canViewPrice($auth));
    }

    public function canViewPriceProvider(): array
    {
        return [
            'guest blocked when setting enabled' => [
                ['user_type' => 'C'],
                'Y',
                '1,2,3',
                false,
            ],
            'admin bypasses restrictions' => [
                ['user_type' => 'A'],
                'Y',
                '1,2,3',
                true,
            ],
            'logged-in customer allowed when in group' => [
                ['user_type' => 'C', 'user_id' => 50, 'usergroup_ids' => [7, 9]],
                'Y',
                '5,9',
                true,
            ],
            'logged-in customer denied when not in group' => [
                ['user_type' => 'C', 'user_id' => 51, 'usergroup_ids' => [3]],
                'N',
                '5,9',
                false,
            ],
            'guest allowed when flag disabled and no restriction' => [
                ['user_type' => 'C'],
                'N',
                '',
                true,
            ],
            'guest allowed with matching group even when flagged off' => [
                ['user_type' => 'C', 'usergroup_ids' => [4]],
                'N',
                '4,8',
                true,
            ],
        ];
    }
}
