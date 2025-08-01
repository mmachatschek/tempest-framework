<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration\View;

use Tempest\Support\Html\HtmlString;
use Tempest\View\Exceptions\ElementWasInvalid;
use Tempest\View\ViewCache;
use Tests\Tempest\Fixtures\Controllers\RelativeViewController;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;

use function Tempest\uri;
use function Tempest\view;

/**
 * @internal
 */
final class TempestViewRendererTest extends FrameworkIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->container->get(ViewCache::class)->clear();
    }

    public function test_view_renderer(): void
    {
        $this->assertSame(
            '<h1>Hello</h1>',
            $this->render('<h1>Hello</h1>'),
        );

        $this->assertSame(
            '<h1>&lt;span&gt;Hello&lt;/span&gt;</h1>',
            $this->render(view('<h1>{{ $this->foo }}</h1>')->data(foo: '<span>Hello</span>')),
        );

        $this->assertSame(
            '<h1></h1>',
            $this->render(view('<h1>{{ $this->foo }}</h1>')),
        );

        $this->assertSame(
            '<h1><span>Hello</span></h1>',
            $this->render(view('<h1>{!! $this->foo !!}</h1>')->data(foo: '<span>Hello</span>')),
        );
    }

    public function test_relative_view_path_rendering(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Relative paths not supported on Windows');
        }

        $this->http
            ->get(uri([RelativeViewController::class, 'asFunction']))
            ->assertOk()
            ->assertSee('Yes!');

        $this->http
            ->get(uri([RelativeViewController::class, 'asObject']))
            ->assertOk()
            ->assertSee('Yes!');
    }

    public function test_if_attribute(): void
    {
        $this->assertSame(
            '',
            $this->render(view('<div :if="$this->show">Hello</div>')->data(show: false)),
        );

        $this->assertSame(
            '<div>Hello</div>',
            $this->render(view('<div :if="$this->show">Hello</div>')->data(show: true)),
        );
    }

    public function test_if_with_other_expression_attributes(): void
    {
        $html = $this->render('<div :if="$this->show" :data="$data">Hello</div>', show: true, data: 'test');

        $this->assertSame(
            '<div data="test">Hello</div>',
            $html,
        );
    }

    public function test_else_with_other_expression_attributes(): void
    {
        $html = $this->render('<div :if="$this->show" :data="$data">Hello</div><div :else :data="$data">Nothing to see</div>', show: false, data: 'test');

        $this->assertSame(
            '<div data="test">Nothing to see</div>',
            $html,
        );
    }

    public function test_elseif_attribute(): void
    {
        $this->assertSame(
            '<div>A</div>',
            $this->render(view('<div :if="$this->a">A</div><div :elseif="$this->b">B</div><div :else>None</div>')->data(a: true, b: true)),
        );

        $this->assertSame(
            '<div>A</div>',
            $this->render(view('<div :if="$this->a">A</div><div :elseif="$this->b">B</div><div :else>None</div>')->data(a: true, b: false)),
        );

        $this->assertSame(
            '<div>B</div>',
            $this->render(view('<div :if="$this->a">A</div><div :elseif="$this->b">B</div><div :else>None</div>')->data(a: false, b: true)),
        );

        $this->assertSame(
            '<div>None</div>',
            $this->render(view('<div :if="$this->a">A</div><div :elseif="$this->b">B</div><div :else>None</div>')->data(a: false, b: false)),
        );

        $this->assertSame(
            '<div>C</div>',
            $this->render(
                view('<div :if="$this->a">A</div><div :elseif="$this->b">B</div><div :elseif="$this->c">C</div><div :else>None</div>')->data(a: false, b: false, c: true),
            ),
        );

        $this->assertSame(
            '<div>B</div>',
            $this->render(view('<div :if="$this->a">A</div><div :elseif="$this->b">B</div><div :elseif="$this->c">C</div><div :else>None</div>')->data(a: false, b: true, c: true)),
        );

        $this->assertSame(
            '<div>None</div>',
            $this->render(
                view('<div :if="$this->a">A</div><div :elseif="$this->b">B</div><div :elseif="$this->c">C</div><div :else>None</div>')->data(a: false, b: false, c: false),
            ),
        );
    }

    public function test_else_if_with_other_expression_attributes(): void
    {
        $html = $this->render('<div :if="$show" :data="$data">Hello</div><div :elseif="$show === false" :data="$data">Nothing to see</div>', show: false, data: 'test');

        $this->assertSame(
            '<div data="test">Nothing to see</div>',
            $html,
        );
    }

    public function test_else_attribute(): void
    {
        $this->assertSame(
            '<div>True</div>',
            $this->render(view('<div :if="$this->show">True</div><div :else>False</div>')->data(show: true)),
        );

        $this->assertSame(
            '<div>False</div>',
            $this->render(view('<div :if="$this->show">True</div><div :else>False</div>')->data(show: false)),
        );
    }

    public function test_foreach_attribute(): void
    {
        $this->assertStringEqualsStringIgnoringLineEndings(
            <<<'HTML'
            <div>a</div>
            <div>b</div>
            HTML,
            $this->render(view('<div :foreach="$this->items as $foo">{{ $foo }}</div>')->data(items: ['a', 'b'])),
        );
    }

    public function test_foreach_consumes_attribute(): void
    {
        $html = $this->render(
            <<<'HTML'
            <x-base :items="$items">
                <table>
                    <tr :foreach="$items as $item">
                        <td>{{ $item }}</td>
                    </tr>
                </table>
            </x-base>
            HTML,
            items: ['a', 'b'],
        );

        $this->assertSnippetsMatch(
            <<<'HTML'
            <html lang="en">
                <head>
                    <title>Home</title>
                </head>
                <body>
                
                
            <table>
                    <tr>
                        <td>a</td>
                    </tr>
            <tr>
                        <td>b</td>
                    </tr>
                </table>


                </body>
                </html>
            HTML,
            $html,
        );
    }

    public function test_forelse_attribute(): void
    {
        $this->assertSame(
            <<<'HTML'
            <div>Empty</div>
            HTML,
            $this->render(view('<div :foreach="$this->items as $foo">{{ $foo }}</div><div :forelse>Empty</div>')->data(items: [])),
        );

        $this->assertSame(
            <<<'HTML'
            <div>a</div>
            HTML,
            $this->render(view('<div :foreach="$this->items as $foo">{{ $foo }}</div><div :forelse>Empty</div>')->data(items: ['a'])),
        );
    }

    public function test_forelse_with_other_expression_attribute(): void
    {
        $this->assertSame(
            <<<'HTML'
            <div data="test">Empty</div>
            HTML,
            $this->render('<div :foreach="$this->items as $foo">{{ $foo }}</div><div :forelse :data="$data">Empty</div>', items: [], data: 'test'),
        );
    }

    public function test_default_slot(): void
    {
        $this->assertSnippetsMatch(
            <<<'HTML'
            <div class="base">Test</div>
            HTML,
            $this->render(
                <<<'HTML'
                <x-base-layout>
                    Test
                </x-base-layout>
                HTML,
            ),
        );
    }

    public function test_implicit_default_slot(): void
    {
        $this->assertStringEqualsStringIgnoringLineEndings(
            <<<'HTML'
            <div class="base">
                
                Test

            </div>
            HTML,
            $this->render(
                <<<'HTML'
                <x-base-layout>
                    Test
                </x-base-layout>
                HTML,
            ),
        );
    }

    public function test_multiple_slots(): void
    {
        $this->assertSnippetsMatch(
            <<<'HTML'
            injected scripts
                


            <div class="base">
                
                Test
                
                

                
                Hi

            </div>



                injected styles
            HTML,
            $this->render(
                <<<'HTML'
                <x-complex-base>
                    Test
                    
                    <x-slot name="scripts">
                    injected scripts
                    </x-slot>
                    
                    <x-slot name="styles">
                    injected styles
                    </x-slot>
                    
                    Hi
                </x-complex-base>
                HTML,
            ),
        );
    }

    public function test_pre(): void
    {
        $this->assertStringEqualsStringIgnoringLineEndings(
            <<<'HTML'
            <pre>a
                    <span class="hl-prop">b</span>
                <span class="hl-type">c</span>
            </pre>
            HTML,
            $this->render(
                <<<'HTML'
                <pre>a
                        <span class="hl-prop">b</span>
                    <span class="hl-type">c</span>
                </pre>
                HTML,
            ),
        );
    }

    public function test_use_statements_are_grouped(): void
    {
        $html = $this->render('<x-view-component-with-use-import></x-view-component-with-use-import><x-view-component-with-use-import></x-view-component-with-use-import>');

        $this->assertStringContainsString('/', $html);
    }

    public function test_raw_and_escaped(): void
    {
        $html = $this->render(view(__DIR__ . '/../../Fixtures/Views/raw-escaped.view.php', var: '<h1>hi</h1>'));

        $this->assertStringEqualsStringIgnoringLineEndings(<<<'HTML'
        &lt;h1&gt;hi&lt;/h1&gt;
        &lt;H1&gt;HI&lt;/H1&gt;
        <h1>hi</h1>
        HTML, $html);
    }

    public function test_html_string(): void
    {
        $html = $this->render(view(__DIR__ . '/../../Fixtures/Views/raw-escaped.view.php', var: HtmlString::createTag('h1', content: 'hi')));

        $this->assertStringEqualsStringIgnoringLineEndings(
            expected: <<<'HTML'
            <h1>hi</h1>
            &lt;H1&gt;HI&lt;/H1&gt;
            <h1>hi</h1>
            HTML,
            actual: $html,
        );
    }

    public function test_no_double_else_attributes(): void
    {
        $this->expectException(ElementWasInvalid::class);

        $this->render(
            <<<'HTML'
            <div :if="false"></div>
            <div :else></div>
            <div :else></div>
            HTML,
        );
    }

    public function test_else_must_be_after_if_or_elseif(): void
    {
        $this->render(
            <<<'HTML'
            <div :if="false"></div>
            <div :else></div>
            HTML,
        );

        $this->render(
            <<<'HTML'
            <div :if="false"></div>
            <div :elseif="false"></div>
            <div :else></div>
            HTML,
        );

        $this->expectException(ElementWasInvalid::class);

        $this->render(
            <<<'HTML'
            <div :else></div>
            HTML,
        );
    }

    public function test_elseif_must_be_after_if_or_elseif(): void
    {
        $this->render(
            <<<'HTML'
            <div :if="false"></div>
            <div :elseif="false"></div>
            <div :elseif="false"></div>
            HTML,
        );

        $this->expectException(ElementWasInvalid::class);

        $this->render(
            <<<'HTML'
            <div :elseif="false"></div>
            HTML,
        );
    }

    public function test_forelse_must_be_before_foreach(): void
    {
        $this->render(
            view(<<<'HTML'
            <div :foreach="$foo as $bar"></div>
            <div :forelse></div>
            HTML, foo: []),
        );

        $this->expectException(ElementWasInvalid::class);

        $this->render(
            <<<'HTML'
            <div :forelse></div>
            HTML,
        );
    }

    public function test_no_double_forelse_attributes(): void
    {
        $this->render(
            view(<<<'HTML'
            <div :foreach="$foo as $bar"></div>
            <div :forelse></div>
            HTML, foo: []),
        );

        $this->expectException(ElementWasInvalid::class);

        $this->render(
            view(<<<'HTML'
            <div :foreach="$foo as $bar"></div>
            <div :forelse></div>
            <div :forelse></div>
            HTML, foo: []),
        );
    }

    public function test_render_element_with_attribute_with_dash(): void
    {
        $view = view(
            <<<HTML
            <div data-theme="tempest"></div>
            HTML,
        );

        $html = $this->render($view);

        $this->assertStringContainsString(
            '<div data-theme="tempest"></div>',
            $html,
        );
    }

    public function test_view_component_with_multiple_attributes(): void
    {
        $expected = '<div class="a">
        a    </div>
<div class="b">
        b    </div>';

        $html = $this->render(view('<x-view-component-with-multiple-attributes a="a" b="b"></x-view-component-with-multiple-attributes>'));
        $this->assertSnippetsMatch($expected, $html);

        $html = $this->render(view('<x-view-component-with-multiple-attributes a="a" :b="\'b\'"></x-view-component-with-multiple-attributes>'));
        $this->assertSnippetsMatch($expected, $html);

        $html = $this->render(view('<x-view-component-with-multiple-attributes :a="\'a\'" :b="\'b\'"></x-view-component-with-multiple-attributes>'));
        $this->assertSnippetsMatch($expected, $html);

        $html = $this->render(view('<x-view-component-with-multiple-attributes :a="\'a\'" b="b"></x-view-component-with-multiple-attributes>'));
        $this->assertSnippetsMatch($expected, $html);
    }

    public function test_slot_with_comment(): void
    {
        $this->assertSnippetsMatch(
            <<<'HTML'
            <div class="base"><!-- example of comment -->

                Test

            </div>
            HTML,
            $this->render(
                <<<'HTML'
                <x-base-layout>
                    <!-- example of comment -->
                    Test
                </x-base-layout>
                HTML,
            ),
        );
    }

    public function test_self_closing_component_tags_are_compiled(): void
    {
        $this->registerViewComponent('x-foo', '<div>foo</div>');

        $this->assertSnippetsMatch(
            '<div>foo</div><div>foo</div>',
            $this->render('<x-foo /><x-foo />'),
        );

        $this->assertSnippetsMatch(
            '<div>foo</div><div>foo</div>',
            $this->render('<x-foo/><x-foo/>'),
        );

        $this->assertSnippetsMatch(
            '<div>foo</div><div>foo</div>',
            $this->render('<x-foo foo="bar" :baz="$hello"/><x-foo foo="bar" :baz="$hello"/>', hello: null),
        );
    }

    public function test_html_tags(): void
    {
        $view = <<<'HTML'
        <!doctype html> 
        <html lang="en"> 
        <!-- test comment -->
        <head> 
            <title>Tempest</title>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <link href="/main.css" rel="stylesheet">
        </head> 
        <body class="flex justify-center items-center">

        <h1 class="text-5xl font-bold text-[#4f95d1]">Tempest</h1>
        </body> 
        </html>
        HTML;

        $html = $this->render($view);

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta charset="UTF-8">', $html);
        $this->assertStringContainsString('<head>', $html);
        $this->assertStringContainsString('<body', $html);
        $this->assertStringContainsString('<!-- test comment -->', $html);
    }

    public function test_view_processors(): void
    {
        $html = $this->render('<div>{{ $global }}</div>');

        $this->assertStringEqualsStringIgnoringLineEndings('<div>test</div>', $html);
    }

    public function test_with_at_symbol_in_html_tag(): void
    {
        $rendered = $this->render(
            view('<button @click="foo">test</button>'),
        );

        $this->assertStringEqualsStringIgnoringLineEndings(
            <<<HTML
            <button @click="foo">test</button>
            HTML,
            $rendered,
        );
    }

    public function test_with_colon_symbol_in_html_tag(): void
    {
        $rendered = $this->render(
            view('<button x-on:click="foo">test</button>'),
        );

        $this->assertStringEqualsStringIgnoringLineEndings(
            <<<HTML
            <button x-on:click="foo">test</button>
            HTML,
            $rendered,
        );
    }

    public function test_loop_variable_can_be_used_within_the_looped_tag(): void
    {
        $html = $this->render(
            view(
                <<<'HTML'
                    <a :foreach="$items as $item" :href="$item->uri">
                        {{ $item->title }}
                    </a>
                HTML,
            )
                ->data(items: [
                    new class {
                        public string $title = 'Item 1';

                        public string $uri = '/item-1';
                    },
                    new class {
                        public string $title = 'Item 2';

                        public string $uri = '/item-2';
                    },
                ]),
        );

        $this->assertSnippetsMatch(<<<'HTML'
        <a href="/item-1">Item 1</a><a href="/item-2">Item 2</a>
        HTML, $html);
    }

    public function test_if_and_foreach_precedence(): void
    {
        $html = $this->render(
            <<<'HTML'
            <div :foreach="$items as $item" :if="$item->show">{{ $item->name }}</div>    
            HTML,
            items: [
                (object) ['name' => 'A', 'show' => true],
                (object) ['name' => 'B', 'show' => false],
                (object) ['name' => 'C', 'show' => true],
            ],
        );

        $this->assertSnippetsMatch('<div>A</div><div>C</div>', $html);

        $html = $this->render(
            <<<'HTML'
            <div :foreach="$items as $item" :if="$show">{{ $item->name }}</div>    
            HTML,
            show: true,
            items: [
                (object) ['name' => 'A', 'show' => true],
                (object) ['name' => 'B', 'show' => false],
                (object) ['name' => 'C', 'show' => true],
            ],
        );

        $this->assertSnippetsMatch('<div>A</div><div>B</div><div>C</div>', $html);

        $html = $this->render(
            <<<'HTML'
            <div :if="$show" :foreach="$items as $item">{{ $item->name }}</div>    
            HTML,
            show: true,
            items: [
                (object) ['name' => 'A', 'show' => true],
                (object) ['name' => 'B', 'show' => false],
                (object) ['name' => 'C', 'show' => true],
            ],
        );

        $this->assertSnippetsMatch('<div>A</div><div>B</div><div>C</div>', $html);

        $html = $this->render(
            <<<'HTML'
            <div :foreach="$items as $item" :if="$show">{{ $item->name }}</div>    
            HTML,
            show: false,
            items: [
                (object) ['name' => 'A', 'show' => true],
                (object) ['name' => 'B', 'show' => false],
                (object) ['name' => 'C', 'show' => true],
            ],
        );

        $this->assertSnippetsMatch('', $html);

        $html = $this->render(
            <<<'HTML'
            <div :if="$show" :foreach="$items as $item">{{ $item->name }}</div>    
            HTML,
            show: false,
            items: [
                (object) ['name' => 'A', 'show' => true],
                (object) ['name' => 'B', 'show' => false],
                (object) ['name' => 'C', 'show' => true],
            ],
        );

        $this->assertSnippetsMatch('', $html);

        $html = $this->render(
            <<<'HTML'
            <div :if="$item->show" :foreach="$items as $item">{{ $item->name }}</div>    
            HTML,
            item: (object) ['show' => true],
            items: [
                (object) ['name' => 'A', 'show' => true],
                (object) ['name' => 'B', 'show' => false],
                (object) ['name' => 'C', 'show' => true],
            ],
        );

        $this->assertSnippetsMatch('<div>A</div><div>B</div><div>C</div>', $html);

        $html = $this->render(
            <<<'HTML'
            <div :if="$item->show ?? null" :foreach="$items as $item">{{ $item->name }}</div>    
            HTML,
            items: [
                (object) ['name' => 'A', 'show' => true],
                (object) ['name' => 'B', 'show' => false],
                (object) ['name' => 'C', 'show' => true],
            ],
        );

        $this->assertSnippetsMatch('', $html);
    }

    public function test_escape_expression_attribute(): void
    {
        $html = $this->render('<div ::escaped="foo">');

        $this->assertSnippetsMatch('<div :escaped="foo"></div>', $html);
    }

    public function test_unclosed_php_tag(): void
    {
        $html = $this->render(<<<'HTML'
        <?php echo 'hi';
        HTML);

        $this->assertSame('hi', $html);
    }

    public function test_view_comments(): void
    {
        $html = $this->render(<<<'HTML'
        <p>{{-- this is a comment --}}this is rendered text</p>{{-- this is a comment --}}
        HTML);

        $this->assertSnippetsMatch('<p>this is rendered text</p>', $html);
    }

    public function test_multiline_view_comments(): void
    {
        $html = $this->render(<<<'HTML'
        {{-- this is a comment
                <div>
                    <!-- Start -->
                    <x-label>{{ Tempest\Intl\translate('test_2') }}</x-label>
                <x-input
                    name="test"
                    type="text"
                    class="py-2.5 sm:py-3 px-4 block w-full border-gray-500 border-1 rounded-lg sm:text-sm focus:border-blue-500 focus:ring-blue-500 disabled:opacity-50 disabled:pointer-events-none dark:bg-neutral-900 dark:border-neutral-700 dark:text-neutral-400 dark:placeholder-neutral-500 dark:focus:ring-neutral-600" placeholder="This is placeholder" />
                <!-- end -->
            </div>
            --}}
        <p>This should be rendered</p>
        HTML);

        $this->assertSnippetsMatch('<p>This should be rendered</p>', $html);
    }
}
