<?php

namespace Phpactor\Extension\LanguageServerCompletion\Tests\Integration;

use Closure;
use Generator;
use Phpactor\Extension\LanguageServerCompletion\Tests\IntegrationTestCase;
use Phpactor\Extension\LanguageServerHover\Renderer\HoverInformation;
use Phpactor\ObjectRenderer\Model\ObjectRenderer;
use Phpactor\ObjectRenderer\ObjectRendererBuilder;
use Phpactor\TestUtils\ExtractOffset;
use Phpactor\WorseReflection\Bridge\Phpactor\MemberProvider\DocblockMemberProvider;
use Phpactor\WorseReflection\Core\SourceCodeLocator\StubSourceLocator;
use Phpactor\WorseReflection\Core\SourceCodeLocator\TemporarySourceLocator;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\WorseReflection\Reflector;
use Phpactor\WorseReflection\ReflectorBuilder;

class MarkdownObjectRendererTest extends IntegrationTestCase
{
    /**
     * @var Reflector
     */
    private $reflector;

    /**
     * @var ObjectRenderer
     */
    private $renderer;

    /**
     * @var TemporarySourceLocator
     */
    private $locator;

    protected function setUp(): void
    {
        $this->workspace()->reset();
        $this->workspace()->mkdir('project');
        $this->locator = new StubSourceLocator(ReflectorBuilder::create()->build(), $this->workspace()->path('project'), $this->workspace()->path('cache'));
        $this->reflector = ReflectorBuilder::create()
            ->addLocator($this->locator)
            ->addMemberProvider(new DocblockMemberProvider())
            ->enableContextualSourceLocation()
            ->build();
        $this->renderer = ObjectRendererBuilder::create()
             ->addTemplatePath(__DIR__ .'/../../../templates/markdown')
             ->enableInterfaceCandidates()
             ->build();
    }

    /**
     * @dataProvider provideHoverInformation
     * @dataProvider provideClass
     * @dataProvider provideInterface
     * @dataProvider provideMethod
     * @dataProvider provideProperty
     * @dataProvider provideConstant
     * @dataProvider provideTrait
     * @dataProvider provideFunction
     * @dataProvider provideSymbolOffset
     * @dataProvider provideType
     */
    public function testRender(string $manifest, Closure $objectFactory, string $expected, bool $capture = false): void
    {
        $this->workspace()->loadManifest($manifest);

        $object = $objectFactory($this->reflector);
        $path = __DIR__ . '/expected/'. $expected;

        if (!file_exists($path)) {
            file_put_contents($path, '');
        }

        $actual = $this->renderer->render($object);

        if ($capture) {
            fwrite(STDOUT, sprintf("\nCaptured %s\n\n>>> START\n%s\n<<< END", $path, $actual));
            file_put_contents($path, $actual);
        }


        self::assertEquals(file_get_contents($path), $actual);
    }

    /**
     * @return Generator<array>
     */
    public function provideHoverInformation(): Generator
    {
        yield 'empty' => [
            '',
            function (Reflector $reflector) {
                return new HoverInformation('', '', $reflector->reflectClassesIn('<?php class Foobar {}')->first());
            },
            'hover_information1.md',
        ];

        yield 'title no docs' => [
            '',
            function (Reflector $reflector) {
                return new HoverInformation('This is my title', '', $reflector->reflectClassesIn('<?php class Foobar {}')->first());
            },
            'hover_information2.md',
        ];

        yield 'title with docs' => [
            '',
            function (Reflector $reflector) {
                return new HoverInformation('This is my title', 'There are my docs', $reflector->reflectClassesIn('<?php class Foobar {}')->first());
            },
            'hover_information3.md',
        ];

        yield 'docs with HTML tags' => [
            '',
            function (Reflector $reflector) {
                return new HoverInformation('This is my title', '<p>There are my docs</p>', $reflector->reflectClassesIn('<?php class Foobar {}')->first());
            },
            'hover_information3.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideClass(): Generator
    {
        yield 'simple class' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn('<?php class Foobar {}')->first();
            },
            'class_reflection1.md'
        ];

        yield 'complex class' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

interface DoesThis
{
}
interface DoesThat
{
}
abstract class SomeAbstract
{
}

class Concrete extends SomeAbstract implements DoesThis, DoesThat
{
    public function __construct(string $foo) {}
    /**
     * @param string|bool|null $bar
     */
    public function foobar(string $foo, $bar): SomeAbstract;
}
EOT
                )->get('Concrete');
            },
            'class_reflection2.md',
        ];

        yield 'class with constants and properties' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class SomeClass
{
    public const FOOBAR = 'bar';
    private const NO= 'none';
    public $foo = 'zed';
    public function foobar(): void {}
}
EOT
                )->get('SomeClass');
            },
            'class_reflection3.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideInterface()
    {
        yield 'complex interface' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

interface DoesThis
{
}
interface DoesThat
{
}

/**
 * Hello documentation
 */
interface AwesomeInterface extends DoesThis, DoesThat
{
    const FOOBAR = "BARFOO";
    public function foo(): string;
}
EOT
                )->get('AwesomeInterface');
            },
            'interface_reflection1.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideTrait()
    {
        yield 'simple trait' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

trait Blah
{
    public function foo();
}
EOT
                )->get('Blah');
            },
            'trait1.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideMethod()
    {
        yield 'simple' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

/**
 * Hello documentation
 */
class OneClass
{
    public function foo();
}
EOT
                )->first()->methods()->get('foo');
            },
            'method1.md',
        ];

        yield 'complex method' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    /**
     * This is my method
     *
     * @param bool|string $foo
     * @param Foobar[] $zed
     */
    public function foo(string $bar, $foo, array $zed): void;
}
EOT
                )->first()->methods()->get('foo');
            },
            'method2.md',
        ];

        yield 'private method' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    private function foo(): void;
}
EOT
                )->first()->methods()->get('foo');
            },
            'method3.md',
        ];

        yield 'static and abstract method' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    abstract public static function foo()
}
EOT
                )->first()->methods()->get('foo');
            },
            'method4.md',
        ];

        yield 'virtual method' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

/**
 * @method string foobar()
 */
class OneClass
{
}
EOT
                )->first()->methods()->get('foobar');
            },
            'method5.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideProperty()
    {
        yield 'simple property' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    public $foobar;
}
EOT
                )->first()->properties()->get('foobar');
            },
            'property1.md',
        ];

        yield 'complex property' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    /**
     * @var Foobar|string
     */
    public $foobar = "bar";
}
EOT
                )->first()->properties()->get('foobar');
            },
            'property2.md',
        ];

        yield 'typed property' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    public string $foobar = "bar";
}
EOT
                )->first()->properties()->get('foobar');
            },
            'property3.md',
        ];

        yield 'virtual property' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

/**
 * @property string $foobar
 */
class OneClass
{
}
EOT
                )->first()->properties()->get('foobar');
            },
            'property4.md',
        ];

        yield 'null property' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    /**
     * @var mixed|foo
     */
    public $foobar;
}
EOT
                )->first()->properties()->get('foobar');
            },
            'property5.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideConstant()
    {
        yield 'simple constant' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    const FOOBAR = "barfoo";
}
EOT
                )->first()->constants()->get('FOOBAR');
            },
            'constant1.md',
        ];

        yield 'complex constant' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectClassesIn(
                    <<<'EOT'
<?php

class OneClass
{
    private const FOOBAR = ['one', 2];
}
EOT
                )->first()->constants()->get('FOOBAR');
            },
            'constant2.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideFunction()
    {
        yield 'simple function' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectFunctionsIn(
                    <<<'EOT'
<?php
function one() {}
EOT
                )->first();
            },
            'function1.md',
        ];

        yield 'complex function' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectFunctionsIn(
                    <<<'EOT'
<?php
function one(string $bar, bool $baz): stdClass {}
EOT
                )->first();
            },
            'function2.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideSymbolOffset()
    {
        yield 'whitespace' => [
            '',
            function (Reflector $reflector) {
                return $reflector->reflectOffset(
                    <<<'EOT'
<?php
EOT
                ,
                    1
                );
            },
            'offset1.md',
        ];

        yield 'var with local vars' => [
            '',
            function (Reflector $reflector) {
                $source = <<<'EOT'
<?php

$foo = 'string';
$bar = 1234;

$<>zed;

EOT
                ;
                [$source, $offset] = ExtractOffset::fromSource($source);
                return $reflector->reflectOffset($source, $offset);
            },
            'offset2.md',
        ];
    }

    /**
     * @return Generator<array>
     */
    public function provideType()
    {
        yield 'mixed' => [
            '',
            function (Reflector $reflector) {
                return Type::mixed();
            },
            'type1.md',
        ];
    }
}
