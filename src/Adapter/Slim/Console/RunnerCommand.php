<?php

namespace AetherUpload\Adapter\Slim\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 把 src/Console/*Runner 包成一个 Symfony 命令：只做参数解析与输出通道转接，业务逻辑全在 Runner 里。
 *
 * 三条命令的行为只有「名字 + 有没有参数」的差别，所以只有一个命令类、由 Application 实例化三次；
 * 命令名走构造参数，**刻意不用 $defaultName/$defaultDescription**（Symfony 6.1 起废弃、7.0 起移除）。
 */
class RunnerCommand extends Command
{
    /** @var callable function (array $arguments, callable $write): int */
    private $handler;

    /** @var array<string,mixed> 可选参数：名 => 默认值 */
    private $arguments;

    /**
     * @param array<string,mixed> $arguments 形如 ['days' => 2]，全部声明为可选参数
     */
    public function __construct(string $name, string $description, callable $handler, array $arguments = [])
    {
        // 必须先赋值再调 parent：Symfony\Console\Command::__construct() 会回调本类的 configure()，
        // 那时属性还没赋值，configure() 里的 foreach 会吃到 null（PHP 8 报 Warning 且参数声明不出来）
        $this->handler = $handler;
        $this->arguments = $arguments;

        parent::__construct($name);

        $this->setDescription($description);
    }

    protected function configure(): void
    {
        foreach ( $this->arguments as $name => $default ) {
            $this->addArgument((string)$name, InputArgument::OPTIONAL, '', $default);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arguments = [];

        foreach ( array_keys($this->arguments) as $name ) {
            $arguments[$name] = $input->getArgument($name);
        }

        $status = ($this->handler)($arguments, static function (string $line) use ($output): void {
            $output->writeln($line);
        });

        return $status === 0 ? self::SUCCESS : self::FAILURE;
    }
}
