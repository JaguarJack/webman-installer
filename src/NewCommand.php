<?php

namespace Webman\Installer\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\ProcessUtils;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use Concerns\ConfiguresPrompts;

    /**
     * Composer 实例。
     *
     * @var \Illuminate\Support\Composer
     */
    protected $composer;

    protected $version;

    protected $isIntallAdmin = false;

    /**
     * 配置命令选项。
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('创建一个新应用')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('force', 'f', InputOption::VALUE_NONE, '强制安装，即使目录已存在');
    }

    /**
     * 在验证输入之前与用户交互。
     *
     * @param InputInterface $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write(PHP_EOL.'  <fg=red>                __
 _      _____  / /_  ____ ___  ____ _____
| | /| / / _ \/ __ \/ __ `__ \/ __ `/ __ \
| |/ |/ /  __/ /_/ / / / / / / /_/ / / / /
|__/|__/\___/_.___/_/ /_/ /_/\__,_/_/ /_/ </>'.PHP_EOL.PHP_EOL);

        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label: '请输入项目名称:',
                placeholder: '例如: example-app',
                required: '项目名称是必需的。',
                validate: fn ($value) => preg_match('/[^\pL\pN\-_.]/', $value) !== 0
                    ? '项目名称只能包含字母、数字、破折号、下划线和句点。'
                    : null,
            ));
        }

        $this->version = select(
            label: '请选择版本:',
            options: [
                '1.0' => '1.0',
                '2.0' => '2.0',
            ],
            default: '2.0'
        );

        $this->isIntallAdmin = confirm(
            '是否安装 webman/admin ?',
            default: false
        );
    }

    /**
     * 执行命令。
     *
     * @param InputInterface $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('name');

        $directory = $name !== '.' ? getcwd().'/'.$name : '.';

        $this->composer = new Composer(new Filesystem(), $directory);

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($input->getOption('force') && $directory === '.') {
            throw new RuntimeException('无法在当前目录进行强制安装！');
        }

        $composer = $this->findComposer();

        $commands = [
            $composer." create-project workerman/webman:~{$this->version} \"$directory\" --remove-vcs --prefer-dist",
        ];

        if ($directory != '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY == 'Windows') {
                array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (PHP_OS_FAMILY != 'Windows') {
            $commands[] = "chmod 755 \"$directory/webman\"";
        }

        $commands[] = "cd {$directory}";

        if ($this->isIntallAdmin) {
            $commands[] = $composer." require -W webman/admin ~{$this->version}";
        } else {
            if ($this->version == '2.0') {
                $commands[] = $composer." require -W webman/database illuminate/pagination illuminate/events symfony/var-dumper";
            }

            if ($this->version == '1.0') {
                $commands[] = $composer." require -W illuminate/database illuminate/pagination illuminate/events symfony/var-dumper laravel/serializable-closure";
            }
        }

        if ($this->runCommands($commands, $input, $output)->isSuccessful()) {
            $output->writeln(PHP_EOL);
            $output->writeln("  <bg=blue;fg=white> 信息 </> [{$name}] 已安装成功！".PHP_EOL);
        }


        $output->writeln("  <bg=blue;fg=white> 信息 </> 启动项目".PHP_EOL);

        if ($this->isIntallAdmin) {
            $output->writeln("  <bg=blue;fg=white> 信息 </> 后台管理地址: http://127.0.0.1:8787/app/admin".PHP_EOL);
        }

        if ($process = $this->runCommands([
            "cd {$directory}",
            PHP_OS_FAMILY == 'Windows' ? $this->phpBinary() . ' windows.php' : $this->phpBinary() . ' start.php start',
        ], $input, $output)) {
           //  $output->writeln('测试项目地址: http://127.0.0.1:8080');
        }

        return $process->getExitCode();
    }

    /**
     * 验证应用程序是否已存在。
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('应用目录已存在！');
        }
    }

    /**
     * 获取环境的 composer 命令。
     *
     * @return string
     */
    protected function findComposer()
    {
        return implode(' ', $this->composer->findComposer());
    }

    /**
     * 获取适当的 PHP 二进制文件的路径。
     *
     * @return string
     */
    protected function phpBinary()
    {
        $phpBinary = (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }

    /**
     * 运行给定的命令。
     *
     * @param  array  $commands
     * @param InputInterface $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string|null  $workingPath
     * @param  array  $env
     * @return \Symfony\Component\Process\Process
     */
    protected function runCommands($commands, InputInterface $input, OutputInterface $output, string $workingPath = null, array $env = [])
    {
        if (! $output->isDecorated()) {
            $commands = array_map(function ($value) {
                if (str_starts_with($value, 'chmod')) {
                    return $value;
                }

                if (str_starts_with($value, 'git')) {
                    return $value;
                }

                return $value.' --no-ansi';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> 警告 </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }

    /**
     * 替换给定文件中的给定字符串。
     *
     * @param  string|array  $search
     * @param  string|array  $replace
     * @param  string  $file
     * @return void
     */
    protected function replaceInFile(string|array $search, string|array $replace, string $file)
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }
}
