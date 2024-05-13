<?php

namespace ajiho\namespaceify;

use Closure;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Parser
{
    /**
     * 包的路径
     */
    protected $packageDir;
    /**
     * composer.json文件中的autoload.files里面的文件路径
     * @var string
     */
    protected $filename;
    /**
     * @var ParserFactory
     */
    protected $parser;

    /**
     * @var PrettyPrinter\Standard
     */
    protected $prettyPrinter;
    /**
     * 想要添加的命名空间
     * @var string
     */
    protected $namespace;
    /**
     * composer.json文件中的autoload.files里面的文件的抽象语法树
     */
    protected $fileAst;
    /**
     * 从filename里提取出来的函数名称数组
     * @var array
     */
    protected $methods;


    public function __construct($packageDir, $filename, $namespace)
    {

        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $this->prettyPrinter = new PrettyPrinter\Standard();
        $this->packageDir = $packageDir;
        $this->filename = $filename;
        $this->namespace = $namespace;
        $this->fileAst = $this->parser->parse(file_get_contents($this->filename));
        $this->methods = $this->getMethods();
    }

    public function run()
    {
        $this->autoloadFilesHandle();
        //处理包目录下所有的php文件
        $this->packageFilesHandle();
    }

    public function autoloadFilesHandle()
    {
        $this->addFileNameSpace();
        $this->removeIfFunctionExists();
        $this->addRootNameSpace();
        file_put_contents($this->filename, $this->prettyPrinter->prettyPrintFile($this->fileAst));
    }

    public function addRootNameSpace()
    {
        $traverser = new NodeTraverser();
        //创建节点访问者来查找 use 声明
        $useDeclarationVisitor = new class extends NodeVisitorAbstract {
            public $uses = [];

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $classNameArr = $use->name->getParts();
                        $className = $use->alias ? $use->alias->name : end($classNameArr);
                        $this->uses[] = $className;
                    }
                }
            }
        };
        $traverser->addVisitor($useDeclarationVisitor);
        $traverser->traverse($this->fileAst);
        //这里无需重新接收
        $traverser->addVisitor(new class($useDeclarationVisitor->uses) extends NodeVisitorAbstract {

            private $uses;

            public function __construct(array $uses)
            {
                $this->uses = $uses;
            }

            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
                    $className = $node->class->toString();
                    //如果类名不在已导入的类中，则添加根命名空间
                    if (!in_array($className, $this->uses)) {
                        $node->class = new Node\Name('\\' . $className);
                    }
                }
            }
        });
        $traverser->traverse($this->fileAst);
    }

    /**
     * 删除文件中if (! function_exists('method')) {} 这种包裹，因为添加了命名空间就不需要这个判断了
     * 当然如果为了保险起见，还可以把method也替换成带命名空间的方法名形式如下
     * if (!function_exists('NameSpace\h')) {} 这里我就简单处理一下
     * */
    public function removeIfFunctionExists()
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class extends NodeVisitorAbstract {
            public function leaveNode(Node $node)
            {
                // 判断节点类型是否为目标条件语句
                if ($node instanceof Node\Stmt\If_ && count($node->stmts) === 1 && $node->stmts[0] instanceof Node\Stmt\Function_) {
                    // 返回函数体节点
                    return $node->stmts[0];
                }
                return $node;
            }
        });
        $this->fileAst = $traverser->traverse($this->fileAst);
    }

    public function packageFilesHandle()
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($this->methods, $this->namespace) extends NodeVisitorAbstract {
            private $methods;
            private $nameSpace;

            public function __construct($methods, $nameSpace)
            {
                $this->methods = $methods;
                $this->nameSpace = '\\' . $nameSpace . '\\';
            }

            public function enterNode(Node $node)
            {
                //判断是否是函数调用节点并进行替换
                foreach ($this->methods as $method) {
                    if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->toString() === $method) {
                        $node->name = new Node\Name($this->nameSpace . $method);
                    }
                }
            }
        });

        $this->getPhpFileContent($this->packageDir, function ($filePath, $content) use($traverser) {
            //解析
            $ast = $this->parser->parse($content);
            $ast = $traverser->traverse($ast);
            file_put_contents($filePath, $this->prettyPrinter->prettyPrintFile($ast));
        });
    }

    public function getPhpFileContent($directory, Closure $callback)
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $autoloadFileRealpath = realpath($this->filename);
        foreach ($iterator as $fileInfo) {
            $filePath = $fileInfo->getPathname();
            $realpath = realpath($filePath);
            //排除传递进来的
            $isAutoloadFile = $autoloadFileRealpath && $realpath && $autoloadFileRealpath === $realpath;
            // 只处理 PHP 文件
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php' && !$isAutoloadFile) {
                $content = file_get_contents($filePath);
                $callback($filePath, $content);
            }
        }
    }

    /**
     * 给文件增加命名空间,避免冲突
     * @return void
     */
    public function addFileNameSpace()
    {
        $finder = new NodeFinder();
        $namespaceNode = $finder->findFirstInstanceOf($this->fileAst, Node\Stmt\Namespace_::class);
        if ($namespaceNode === null) {
            // 没有命名空间,则添加这个命名空间
            // 查找 declare 声明位置
            $declareNode = null;
            foreach ($this->fileAst as $stmt) {
                if ($stmt instanceof Node\Stmt\Declare_) {
                    $declareNode = $stmt;
                    break;
                }
            }
            $namespaceNode = new Node\Stmt\Namespace_(new Node\Name($this->namespace));
            // 如果找到了 declare 声明，则在其后插入命名空间节点
            if ($declareNode !== null) {
                $index = array_search($declareNode, $this->fileAst, true) + 1;
                array_splice($this->fileAst, $index, 0, [$namespaceNode]);
            } else {
                // 如果没有找到 declare 声明，则直接在文件顶部插入命名空间节点
                $fileTop = reset($this->fileAst);
                $index = array_search($fileTop, $this->fileAst, true);
                array_splice($this->fileAst, $index, 0, [$namespaceNode]);
            }
        }
    }

    public function getMethods()
    {
        try {
            // 遍历 AST 并提取函数名称
            $traverser = new NodeTraverser();
            $visitor = new class extends NodeVisitorAbstract {
                public $functionNames = [];

                public function enterNode(Node $node)
                {
                    if ($node instanceof Node\Stmt\Function_) {
                        $this->functionNames[] = $node->name->toString();
                    }
                }
            };
            $traverser->addVisitor($visitor);
            $traverser->traverse($this->fileAst);
            return $visitor->functionNames;
        } catch (Error $error) {
            return [];
        }
    }
}
