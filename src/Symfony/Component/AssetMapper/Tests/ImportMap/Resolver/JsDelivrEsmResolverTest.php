<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Tests\ImportMap\Resolver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\PackageRequireOptions;
use Symfony\Component\AssetMapper\ImportMap\Resolver\JsDelivrEsmResolver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class JsDelivrEsmResolverTest extends TestCase
{
    /**
     * @dataProvider provideResolvePackagesTests
     */
    public function testResolvePackages(array $packages, array $expectedRequests, array $expectedResolvedPackages)
    {
        $responses = [];
        foreach ($expectedRequests as $expectedRequest) {
            $responses[] = function ($method, $url) use ($expectedRequest) {
                $this->assertSame('GET', $method);
                $this->assertStringEndsWith($expectedRequest['url'], $url);

                $body = 'any body';
                if (isset($expectedRequest['response']['body'])) {
                    $body = \is_array($expectedRequest['response']['body']) ? json_encode($expectedRequest['response']['body']) : $expectedRequest['response']['body'];
                }

                return new MockResponse($body);
            };
        }

        $httpClient = new MockHttpClient($responses);

        $provider = new JsDelivrEsmResolver($httpClient);
        $actualResolvedPackages = $provider->resolvePackages($packages);
        $this->assertCount(\count($expectedResolvedPackages), $actualResolvedPackages);
        foreach ($actualResolvedPackages as $package) {
            $importName = $package->requireOptions->importName;
            $this->assertArrayHasKey($importName, $expectedResolvedPackages);
            $this->assertSame($expectedResolvedPackages[$importName]['version'], $package->version);
        }

        $this->assertSame(\count($expectedRequests), $httpClient->getRequestsCount());
    }

    public static function provideResolvePackagesTests(): iterable
    {
        yield 'require single lodash package' => [
            'packages' => [new PackageRequireOptions('lodash')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/lodash/resolved',
                    'response' => ['body' => ['version' => '1.2.3']],
                ],
                [
                    'url' => '/lodash@1.2.3/+esm',
                ],
                [
                    'url' => '/v1/packages/npm/lodash@1.2.3/entrypoints',
                    'response' => ['body' => ['entrypoints' => []]],
                ],
            ],
            'expectedResolvedPackages' => [
                'lodash' => [
                    'version' => '1.2.3',
                ],
            ],
        ];

        yield 'require non-scoped package with version' => [
            'packages' => [new PackageRequireOptions('lodash', '^2')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/lodash/resolved?specifier=%5E2',
                    'response' => ['body' => ['version' => '2.1.3']],
                ],
                [
                    'url' => '/lodash@2.1.3/+esm',
                ],
                [
                    'url' => '/v1/packages/npm/lodash@2.1.3/entrypoints',
                    'response' => ['body' => ['entrypoints' => []]],
                ],
            ],
            'expectedResolvedPackages' => [
                'lodash' => [
                    'version' => '2.1.3',
                ],
            ],
        ];

        yield 'require scoped package with version' => [
            'packages' => [new PackageRequireOptions('@hotwired/stimulus', '^3')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/@hotwired/stimulus/resolved?specifier=%5E3',
                    'response' => ['body' => ['version' => '3.1.3']],
                ],
                [
                    'url' => '/@hotwired/stimulus@3.1.3/+esm',
                ],
                [
                    'url' => '/v1/packages/npm/@hotwired/stimulus@3.1.3/entrypoints',
                    'response' => ['body' => ['entrypoints' => []]],
                ],
            ],
            'expectedResolvedPackages' => [
                '@hotwired/stimulus' => [
                    'version' => '3.1.3',
                ],
            ],
        ];

        yield 'require non-scoped package with path' => [
            'packages' => [new PackageRequireOptions('chart.js/auto', '^3')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/chart.js/resolved?specifier=%5E3',
                    'response' => ['body' => ['version' => '3.0.1']],
                ],
                [
                    'url' => '/chart.js@3.0.1/auto/+esm',
                ],
            ],
            'expectedResolvedPackages' => [
                'chart.js/auto' => [
                    'version' => '3.0.1',
                ],
            ],
        ];

        yield 'require scoped package with path' => [
            'packages' => [new PackageRequireOptions('@chart/chart.js/auto', '^3')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/@chart/chart.js/resolved?specifier=%5E3',
                    'response' => ['body' => ['version' => '3.0.1']],
                ],
                [
                    'url' => '/@chart/chart.js@3.0.1/auto/+esm',
                ],
            ],
            'expectedResolvedPackages' => [
                '@chart/chart.js/auto' => [
                    'version' => '3.0.1',
                ],
            ],
        ];

        yield 'require package that imports another' => [
            'packages' => [new PackageRequireOptions('@chart/chart.js/auto', '^3')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/@chart/chart.js/resolved?specifier=%5E3',
                    'response' => ['body' => ['version' => '3.0.1']],
                ],
                [
                    'url' => '/@chart/chart.js@3.0.1/auto/+esm',
                    'response' => ['body' => 'import{Color as t}from"/npm/@kurkle/color@0.3.2/+esm";function e(){}const i=(()='],
                ],
                [
                    'url' => '/v1/packages/npm/@kurkle/color/resolved?specifier=0.3.2',
                    'response' => ['body' => ['version' => '0.3.2']],
                ],
                [
                    'url' => '/@kurkle/color@0.3.2/+esm',
                ],
                [
                    'url' => '/v1/packages/npm/@kurkle/color@0.3.2/entrypoints',
                    'response' => ['body' => ['entrypoints' => []]],
                ],
            ],
            'expectedResolvedPackages' => [
                '@chart/chart.js/auto' => [
                    'version' => '3.0.1',
                ],
                '@kurkle/color' => [
                    'version' => '0.3.2',
                ],
            ],
        ];

        yield 'require single CSS package' => [
            'packages' => [new PackageRequireOptions('bootstrap/dist/css/bootstrap.min.css')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/bootstrap/resolved',
                    'response' => ['body' => ['version' => '3.3.0']],
                ],
                [
                    // CSS is detected: +esm is left off
                    'url' => '/bootstrap@3.3.0/dist/css/bootstrap.min.css',
                ],
            ],
            'expectedResolvedPackages' => [
                'bootstrap/dist/css/bootstrap.min.css' => [
                    'version' => '3.3.0',
                ],
            ],
        ];

        yield 'require package with style key grabs the CSS' => [
            'packages' => [new PackageRequireOptions('bootstrap', '^5')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/bootstrap/resolved?specifier=%5E5',
                    'response' => ['body' => ['version' => '5.2.0']],
                ],
                [
                    'url' => '/bootstrap@5.2.0/+esm',
                ],
                [
                    'url' => '/v1/packages/npm/bootstrap@5.2.0/entrypoints',
                    'response' => ['body' => ['entrypoints' => [
                        'css' => ['file' => '/dist/css/bootstrap.min.css'],
                    ]]],
                ],
                [
                    'url' => '/v1/packages/npm/bootstrap/resolved?specifier=5.2.0',
                    'response' => ['body' => ['version' => '5.2.0']],
                ],
                [
                    // grab the found CSS
                    'url' => '/bootstrap@5.2.0/dist/css/bootstrap.min.css',
                ],
            ],
            'expectedResolvedPackages' => [
                'bootstrap' => [
                    'version' => '5.2.0',
                ],
                'bootstrap/dist/css/bootstrap.min.css' => [
                    'version' => '5.2.0',
                ],
            ],
        ];

        yield 'require path in package skips grabbing the style key' => [
            'packages' => [new PackageRequireOptions('bootstrap/dist/modal.js', '^5')],
            'expectedRequests' => [
                [
                    'url' => '/v1/packages/npm/bootstrap/resolved?specifier=%5E5',
                    'response' => ['body' => ['version' => '5.2.0']],
                ],
                [
                    'url' => '/bootstrap@5.2.0/dist/modal.js/+esm',
                ],
            ],
            'expectedResolvedPackages' => [
                'bootstrap/dist/modal.js' => [
                    'version' => '5.2.0',
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideDownloadPackagesTests
     */
    public function testDownloadPackages(array $importMapEntries, array $expectedRequests, array $expectedReturn, array $expectedDependencies = [])
    {
        $responses = [];
        foreach ($expectedRequests as $expectedRequest) {
            $responses[] = function ($method, $url) use ($expectedRequest) {
                $this->assertSame('GET', $method);
                $this->assertStringEndsWith($expectedRequest['url'], $url);

                return new MockResponse($expectedRequest['body']);
            };
        }

        $httpClient = new MockHttpClient($responses);

        $provider = new JsDelivrEsmResolver($httpClient);
        $actualReturn = $provider->downloadPackages($importMapEntries);

        foreach ($actualReturn as $key => $data) {
            $actualReturn[$key]['content'] = trim($data['content']);
        }
        $this->assertCount(\count($expectedReturn), $actualReturn);

        $this->assertSame($expectedReturn, $actualReturn);
        $this->assertSame(\count($expectedRequests), $httpClient->getRequestsCount());
    }

    public static function provideDownloadPackagesTests()
    {
        yield 'single package' => [
            ['lodash' => self::createRemoteEntry('lodash', version: '1.2.3')],
            [
                [
                    'url' => '/lodash@1.2.3/+esm',
                    'body' => 'lodash contents',
                ],
            ],
            [
                'lodash' => ['content' => 'lodash contents', 'dependencies' => []],
            ],
        ];

        yield 'importName differs from package specifier' => [
            ['lodash' => self::createRemoteEntry('some_alias', version: '1.2.3', packageSpecifier: 'lodash')],
            [
                [
                    'url' => '/lodash@1.2.3/+esm',
                    'body' => 'lodash contents',
                ],
            ],
            [
                'lodash' => ['content' => 'lodash contents', 'dependencies' => []],
            ],
        ];

        yield 'package with path' => [
            ['lodash' => self::createRemoteEntry('chart.js/auto', version: '4.5.6')],
            [
                [
                    'url' => '/chart.js@4.5.6/auto/+esm',
                    'body' => 'chart.js contents',
                ],
            ],
            [
                'lodash' => ['content' => 'chart.js contents', 'dependencies' => []],
            ],
        ];

        yield 'css file' => [
            ['lodash' => self::createRemoteEntry('bootstrap/dist/bootstrap.css', version: '5.0.6', type: ImportMapType::CSS)],
            [
                [
                    'url' => '/bootstrap@5.0.6/dist/bootstrap.css',
                    'body' => 'bootstrap.css contents',
                ],
            ],
            [
                'lodash' => ['content' => 'bootstrap.css contents', 'dependencies' => []],
            ],
        ];

        yield 'multiple files' => [
            [
                'lodash' => self::createRemoteEntry('lodash', version: '1.2.3'),
                'chart.js/auto' => self::createRemoteEntry('chart.js/auto', version: '4.5.6'),
                'bootstrap/dist/bootstrap.css' => self::createRemoteEntry('bootstrap/dist/bootstrap.css', version: '5.0.6', type: ImportMapType::CSS),
            ],
            [
                [
                    'url' => '/lodash@1.2.3/+esm',
                    'body' => 'lodash contents',
                ],
                [
                    'url' => '/chart.js@4.5.6/auto/+esm',
                    'body' => 'chart.js contents',
                ],
                [
                    'url' => '/bootstrap@5.0.6/dist/bootstrap.css',
                    'body' => 'bootstrap.css contents',
                ],
            ],
            [
                'lodash' => ['content' => 'lodash contents', 'dependencies' => []],
                'chart.js/auto' => ['content' => 'chart.js contents', 'dependencies' => []],
                'bootstrap/dist/bootstrap.css' => ['content' => 'bootstrap.css contents', 'dependencies' => []],
            ],
        ];

        yield 'make imports relative' => [
            [
                '@chart.js/auto' => self::createRemoteEntry('chart.js/auto', version: '1.2.3'),
            ],
            [
                [
                    'url' => '/chart.js@1.2.3/auto/+esm',
                    'body' => 'import{Color as t}from"/npm/@kurkle/color@0.3.2/+esm";function e(){}const i=(()=',
                ],
            ],
            [
                '@chart.js/auto' => [
                    'content' => 'import{Color as t}from"@kurkle/color";function e(){}const i=(()=',
                    'dependencies' => ['@kurkle/color'],
                ],
            ],
        ];

        yield 'make imports point to file and relative' => [
            [
                'twig' => self::createRemoteEntry('twig', version: '1.16.0'),
            ],
            [
                [
                    'url' => '/twig@1.16.0/+esm',
                    'body' => 'import e from"/npm/locutus@2.0.16/php/strings/sprintf/+esm";console.log()',
                ],
            ],
            [
                'twig' => [
                    'content' => 'import e from"locutus/php/strings/sprintf";console.log()',
                    'dependencies' => ['locutus/php/strings/sprintf'],
                ],
            ],
        ];

        yield 'js sourcemap is removed' => [
            [
                '@chart.js/auto' => self::createRemoteEntry('chart.js/auto', version: '1.2.3'),
            ],
            [
                [
                    'url' => '/chart.js@1.2.3/auto/+esm',
                    'body' => 'as Ticks,ta as TimeScale,ia as TimeSeriesScale,oo as Title,wo as Tooltip,Ci as _adapters,us as _detectPlatform,Ye as animator,Si as controllers,tn as default,St as defaults,Pn as elements,qi as layouts,ko as plugins,na as registerables,Ps as registry,sa as scales};
                    //# sourceMappingURL=/sm/bc823a081dbde2b3a5424732858022f831d3f2978d59498cd938e0c2c8cf9ec0.map',
                ],
            ],
            [
                '@chart.js/auto' => [
                    'content' => 'as Ticks,ta as TimeScale,ia as TimeSeriesScale,oo as Title,wo as Tooltip,Ci as _adapters,us as _detectPlatform,Ye as animator,Si as controllers,tn as default,St as defaults,Pn as elements,qi as layouts,ko as plugins,na as registerables,Ps as registry,sa as scales};',
                    'dependencies' => [],
                ],
            ],
        ];

        yield 'css file removes importmap' => [
            ['lodash' => self::createRemoteEntry('bootstrap/dist/bootstrap.css', version: '5.0.6', type: ImportMapType::CSS)],
            [
                [
                    'url' => '/bootstrap@5.0.6/dist/bootstrap.css',
                    'body' => 'print-table-row{display:table-row!important}.d-print-table-cell{display:table-cell!important}.d-print-flex{display:flex!important}.d-print-inline-flex{display:inline-flex!important}.d-print-none{display:none!important}}
                    /*# sourceMappingURL=bootstrap.min.css.map */',
                ],
            ],
            [
                'lodash' => [
                    'content' => 'print-table-row{display:table-row!important}.d-print-table-cell{display:table-cell!important}.d-print-flex{display:flex!important}.d-print-inline-flex{display:inline-flex!important}.d-print-none{display:none!important}}',
                    'dependencies' => [],
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideImportRegex
     */
    public function testImportRegex(string $subject, array $expectedPackages)
    {
        preg_match_all(JsDelivrEsmResolver::IMPORT_REGEX, $subject, $matches);

        $this->assertCount(\count($expectedPackages), $matches[0]);
        $expectedNames = [];
        $expectedVersions = [];
        foreach ($expectedPackages as $packageData) {
            $expectedNames[] = $packageData[0];
            $expectedVersions[] = $packageData[1];
        }
        $actualNames = [];
        foreach ($matches[1] as $i => $name) {
            $actualNames[] = $name.$matches[3][$i];
        }

        $this->assertSame($expectedNames, $actualNames);
        $this->assertSame($expectedVersions, $matches[2]);
    }

    public static function provideImportRegex(): iterable
    {
        yield 'standard import format' => [
            'import{Color as t}from"/npm/@kurkle/color@0.3.2/+esm";import t from"/npm/jquery@3.7.0/+esm";import e from"/npm/popper.js@1.16.1/+esm";console.log("yo");',
            [
                ['@kurkle/color', '0.3.2'],
                ['jquery', '3.7.0'],
                ['popper.js', '1.16.1'],
            ],
        ];

        yield 'export and import format' => [
            'export*from"/npm/@vue/runtime-dom@3.3.4/+esm";const e=()=>{};export{e as compile};export default null;',
            [
                ['@vue/runtime-dom', '3.3.4'],
            ],
        ];

        yield 'multiple export format & import' => [
            'import{defineComponent as e,nextTick as t,createVNode as n,getCurrentInstance as r,watchPostEffect as s,onMounted as o,onUnmounted as i,h as a,BaseTransition as l,BaseTransitionPropsValidators as c,Fragment as u,Static as p,useTransitionState as f,onUpdated as d,toRaw as m,getTransitionRawChildren as h,setTransitionHooks as v,resolveTransitionHooks as g,createRenderer as _,createHydrationRenderer as b,camelize as y,callWithAsyncErrorHandling as C}from"/npm/@vue/runtime-core@3.3.4/+esm";export*from"/npm/@vue/runtime-core@3.3.4/+esm";import{isArray as S,camelize as E,toNumber as A,hyphenate as w,extend as T,EMPTY_OBJ as x,isObject as P,looseToNumber as k,looseIndexOf as L,isSet as N,looseEqual as $,isFunction as R,isString as M,invokeArrayFns as V,isOn as B,isModelListener as D,capitalize as I,isSpecialBooleanAttr as O,includeBooleanAttr as F}from"/npm/@vue/shared@3.3.4/+esm";const U="undefined"!=typeof document?',
            [
                ['@vue/runtime-core', '3.3.4'],
                ['@vue/runtime-core', '3.3.4'],
                ['@vue/shared', '3.3.4'],
            ],
        ];

        yield 'adjacent import and export statements' => [
            'import e from"/npm/datatables.net@2.1.1/+esm";export{default}from"/npm/datatables.net@2.1.1/+esm";',
            [
                ['datatables.net', '2.1.1'],
                ['datatables.net', '2.1.1'], // for the export syntax
            ],
        ];

        yield 'import statements with paths' => [
            'import e from"/npm/locutus@2.0.16/php/strings/sprintf/+esm";import t from"/npm/locutus@2.0.16/php/strings/vsprintf/+esm"',
            [
                ['locutus/php/strings/sprintf', '2.0.16'],
                ['locutus/php/strings/vsprintf', '2.0.16'],
            ],
        ];
    }

    private static function createRemoteEntry(string $importName, string $version, ImportMapType $type = ImportMapType::JS, string $packageSpecifier = null): ImportMapEntry
    {
        $packageSpecifier = $packageSpecifier ?? $importName;

        return ImportMapEntry::createRemote($importName, $type, path: 'does not matter', version: $version, packageModuleSpecifier: $packageSpecifier, isEntrypoint: false);
    }
}
