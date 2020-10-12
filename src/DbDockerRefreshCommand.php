<?php

namespace Axelerant\DbDocker;

use Composer\Command\BaseCommand;
use GitElephant\Repository;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbDockerRefreshCommand extends BaseCommand
{

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    protected function configure()
    {
        $this->setName('db-refresh')
            ->setDescription('Refresh Docker image for the database.')
            ->addOption('docker-tag', 't', InputOption::VALUE_OPTIONAL,
                'The Docker tag to build')
            ->addOption('git-remote', 'r', InputOption::VALUE_OPTIONAL,
                'The git remote to use to determine the image name', 'origin');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        // Determine the Docker image name.
        $imageId = $this->getImageId();

        $this->execCmd("docker pull " . $imageId);
    }

    protected function getImageId(): string
    {
        // We can safely use `getcwd()` even in a subdirectory.
        $git = new Repository(getcwd());
        $tag = $this->input->getOption('docker-tag');
        if (!$tag) {
            $tag = $git->getMainBranch()->getName();
            $this->output->writeln(sprintf("<info>Docker tag not specified. Using current branch name: %s</info>",
                $tag));

            // We should be using the tag 'latest' if the current branch is 'master'.
            if ($tag == 'master') {
                $tag = 'latest';
                $this->output->writeln("<info>Using Docker tag 'latest' for branch 'master'.</info>",
                    OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        // Throws an exception if the remote not found, so we don't have to.
        $remote = $git->getRemote($this->input->getOption('git-remote'));

        // Determine the image name (path) from the git remote URL.
        return $this->getImagePathFromRepoUrl($remote->getFetchURL(), $tag);
    }

    protected function getImagePathFromRepoUrl(string $url, string $tag): string
    {
        if (!preg_match('/^[^@]*@([^:]*):(.*)\.git$/', $url, $matches)) {
            throw new InvalidOptionException("The specified git remote URL couldn't be parsed");
        }

        $host = $matches[1];
        $path = $matches[2];
        switch ($host) {
            case 'gitlab.axl8.xyz':
                $registryDomain = 'registry.axl8.xyz';
                break;
            case 'gitorious.xyz':
            case 'code.axelerant.com':
                $registryDomain = 'registry.gitorious.xyz';
                break;
            default:
                throw new InvalidOptionException("The specified git remote URL isn't supported");
        }

        return sprintf("%s/%s/db:%s", $registryDomain, strtolower($path), $tag);
    }

    protected function execCmd($cmd): void
    {

        $this->output->writeln(sprintf("<info>Running '%s'</info>", $cmd),
            OutputInterface::VERBOSITY_VERBOSE);
        exec($cmd, $res, $code);
        if ($code != 0) {
            $this->output->writeln(sprintf("<error>Command returned exit code '%d'</error>",
                $code));
        }
        $this->output->writeln($res,
            OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_VERBOSE);

        if ($code != 0) {
            $msg = sprintf("Command returned exit code '%d'\n%s",
                $code, implode("\n", $res));
            throw new RuntimeException($msg, $code);
        }
    }
}
