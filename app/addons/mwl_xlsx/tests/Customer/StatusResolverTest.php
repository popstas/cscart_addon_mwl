<?php

namespace Tygh\Addons\MwlXlsx\Tests\Customer;

use PHPUnit\Framework\TestCase;
use Tygh\Addons\MwlXlsx\Customer\StatusResolver;

class StatusResolverTest extends TestCase
{
    public function testResolveStatusReturnsHighestPriorityActiveGroup(): void
    {
        $resolver = $this->createResolver(
            [
                ['usergroup_id' => 5, 'status' => 'A'],
                ['usergroup_id' => 9, 'status' => 'A'],
            ],
            [
                ['usergroup_id' => 5, 'usergroup' => 'Local'],
                ['usergroup_id' => 9, 'usergroup' => 'Global'],
            ]
        );

        $this->assertSame('Global', $resolver->resolveStatus());
    }

    public function testResolveStatusSkipsInactiveGroups(): void
    {
        $resolver = $this->createResolver(
            [
                ['usergroup_id' => 7, 'status' => 'D'],
                ['usergroup_id' => 8, 'status' => 'A'],
            ],
            [
                ['usergroup_id' => 7, 'usergroup' => 'Global'],
                ['usergroup_id' => 8, 'usergroup' => 'Continental'],
            ]
        );

        $this->assertSame('Continental', $resolver->resolveStatus());
    }

    public function testResolveStatusLabelHonorsLanguageMapping(): void
    {
        $resolver = new StatusResolver(
            function (): array {
                return ['user_id' => 42];
            },
            function (): array {
                return [
                    'usergroups' => [
                        ['usergroup_id' => 3, 'status' => 'A'],
                    ],
                ];
            },
            function (): array {
                return [
                    ['usergroup_id' => 3, 'usergroup' => 'National'],
                ];
            },
            function (): string {
                return 'ru';
            },
            null,
            [
                'ru' => ['National' => 'Национальный'],
                'en' => ['National' => 'National'],
            ]
        );

        $this->assertSame('Национальный', $resolver->resolveStatusLabel());
        $this->assertSame('National', $resolver->resolveStatusLabel('en'));
    }

    private function createResolver(array $user_usergroups, array $available_usergroups): StatusResolver
    {
        return new StatusResolver(
            function (): array {
                return ['user_id' => 11];
            },
            function () use ($user_usergroups): array {
                return [
                    'usergroups' => $user_usergroups,
                ];
            },
            function () use ($available_usergroups): array {
                return $available_usergroups;
            },
            function (): string {
                return 'en';
            }
        );
    }
}
