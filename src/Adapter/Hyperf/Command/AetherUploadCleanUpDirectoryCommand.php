<?php

namespace AetherUpload\Adapter\Hyperf\Command;

use AetherUpload\Console\CleanUpDirectoryRunner;
use Hyperf\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

class AetherUploadCleanUpDirectoryCommand extends Command
{
    /** @var string|null 与 Hyperf\Command\Command::$name 的类型保持一致 */
    protected ?string $name = 'aetherupload:clean';

    /** @var string */
    protected string $description = 'Remove partial files which are created a few days ago';

    protected function configure(): void
    {
        parent::configure();

        // 用 configure() 而不是 $signature：签名字符串要多走一层 Hyperf 自己的解析器，
        // 参数定义直接交给 Symfony Console 最省事
        $this->addArgument('days', InputArgument::OPTIONAL, 'The number of days from today', 2);
    }

    /**
     * 只做参数解析与输出通道转接：业务逻辑在 CleanUpDirectoryRunner，
     * 另外五个框架的命令壳复用同一份。
     */
    public function handle(): int
    {
        return (new CleanUpDirectoryRunner())->run(
            function (string $line): void {
                $this->line($line);
            },
            (int)$this->input->getArgument('days')
        );
    }
}
