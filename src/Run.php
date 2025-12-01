<?php

namespace Pierstoval\Threads;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;
use Vazaha\Mastodon\ApiClient;
use Vazaha\Mastodon\Exceptions\TooManyRequestsException;
use Vazaha\Mastodon\Factories\ApiClientFactory;
use Vazaha\Mastodon\Models\StatusModel;
use function count;
use function dirname;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function sprintf;

class Run extends Command
{
    private SymfonyStyle $io;
    private ApiClient $client;
    private string $projectDir;
    private string $cacheDir;
    private string $cachedDataFile;
    private array $cachedData;

    protected function configure(): void
    {
        $this
            ->setName('threads:run')
            ->addArgument('account_name', InputArgument::REQUIRED, 'Account name')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Whether to use cache or not')
            ->addOption('minimum-thread-size', 'm', InputOption::VALUE_OPTIONAL, 'Minimum of replies to consider it a "thread".', 2)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->projectDir = dirname(__DIR__);
        $this->cacheDir = $this->projectDir.'/cache';
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created', $this->cacheDir));
        }
        $this->cachedDataFile = $this->cacheDir.'/cached_data.json';
        $this->cachedData = [];
        if (\is_file($this->cachedDataFile) && !$input->getOption('no-cache')) {
            $this->cachedData = json_decode(file_get_contents($this->cachedDataFile), true, 512, JSON_THROW_ON_ERROR);
        }

        $envFile = $this->projectDir.'/.env';
        if (!is_file($envFile)) {
            $this->io->error(sprintf(
                'Env file "%s" does not exist. You can create it by copy/pasting the "%s" file.',
                $envFile,
                $envFile.'.example',
            ));

            return 1;
        }

        $env = new Dotenv();
        $env->loadEnv($envFile);

        $keys = [
            'APP_INSTANCE',
            'APP_ID',
            'APP_SECRET',
            'APP_ACCESS_TOKEN',
        ];
        foreach ($keys as $key) {
            if (!isset($_ENV[$key])) {
                $this->io->error(\sprintf('Environment key "%s" is not set. Please make sure all the variables are set in the "%s" file.'."\n".'List of variables: %s', 'APP_INSTANCE', $envFile, implode(', ', $keys)));
            }
        }

        $appInstance = $_ENV['APP_INSTANCE'];
        $appId = $_ENV['APP_ID'];
        $appSecret = $_ENV['APP_SECRET'];
        $appAccessToken = $_ENV['APP_ACCESS_TOKEN'];

        $factory = new ApiClientFactory();
        $this->client = $factory->build();
        $this->client->setBaseUri('https://'.$appInstance);
        $this->client->setAccessToken($appAccessToken);

        $this->io->title('Running Threads');

        $accountName = $input->getArgument('account_name');

        $account = $this->client->methods()->accounts()->lookup($accountName);

        $id = $account->id;

        $statuses = $this->cachedData['statuses'] ?? [];
        $lastId = $this->cachedData['last_id'] ?? null;
        $actualLastId = null;

        $progress = new ProgressIndicator(
            $this->io,
            indicatorChangeInterval: 10,
            indicatorValues: ['🌑', '🌒', '🌓', '🌔', '🌕', '🌖', '🌗', '🌘'],
        );
        $progress->start('Statuses found: 0');
        $minimumThreadSize = (int) $input->getOption('minimum-thread-size');
        while (true) {
            $progress->setMessage('Statuses found: '.\count($statuses));
            $progress->advance();
            if ($lastId && $actualLastId === $lastId) {
                $progress->finish('Apparently found enough posts');
                break;
            }
            $actualLastId = $lastId;
            try {
                /** @var StatusModel[] $newStatuses */
                $newStatuses = $this->client->methods()->accounts()->statuses(
                    id: $id,
                    max_id: $lastId,
                    limit: 40,
                )->all();
                if (!count($newStatuses)) {
                    $progress->finish(' No more statuses to check');
                    break;
                }

                foreach ($newStatuses as $status) {
                    $progress->setMessage('Statuses found: '.\count($statuses));
                    $progress->advance();
                    $lastId = $status->id;
                    if (isset($statuses[$status->id])) {
                        continue;
                    }
                    $keys = [
                        'id',
                        'uri',
                        'created_at',
                        'content',
                        'visibility',
                        'spoiler_text',
                        'tags',
                        'in_reply_to_id',
                        'in_reply_to_account_id',
                    ];
                    $store = [];
                    foreach ($keys as $key) {
                        $progress->setMessage('Statuses found: '.\count($statuses));
                        $store[$key] = $status->$key;
                    }
                    $statuses[$status->id] = $store;
                }
            } catch (TooManyRequestsException $e) {
                $progress->finish(\sprintf('Too many requests after id "%s".', $lastId));
                break;
            } catch (Throwable $e) {
                $progress->finish('Error');
                do {
                    $this->io->error([
                        $e->getMessage(),
                        $e->getTraceAsString(),
                    ]);
                } while ($e = $e->getPrevious());
                break;
            }
        }
        try {
            $progress->finish('');
        } catch (\Throwable) {}

        $this->cachedData['last_id'] = $lastId;
        $this->cachedData['statuses'] = $statuses;
        file_put_contents($this->cachedDataFile, json_encode($this->cachedData, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));

        $this->io->info('Number of statuses: '. count($statuses));

        $tree = [];
        foreach ($statuses as $id => $status) {
            $parents = [];
            $currentStatus = $status;

            // Remonter la chaîne des parents avec do-while
            if ($currentStatus['in_reply_to_id'] !== null) {
                do {
                    $parentId = $currentStatus['in_reply_to_id'];

                    if (!isset($statuses[$parentId])) {
                        break;
                    }

                    $parents[] = $parentId;
                    $currentStatus = $statuses[$parentId];

                } while ($currentStatus['in_reply_to_id'] !== null);
            }

            if (count($parents) <= $minimumThreadSize) {
                // Not a thread
                continue;
            }

            $tree[$id] = $parents;
        }

        $threads = [];
        foreach ($tree as $id => $items) {
            $firstPostId = array_last($items);
            if (isset($threads[$firstPostId])) {
                $thread = $threads[$firstPostId];
                if (count($items) > count($thread)) {
                    $threads[$firstPostId] = array_reverse($items);
                    $threads[$firstPostId][] = (string) $id;
                }
            } else {
                $threads[$firstPostId] = array_reverse($items);
                $threads[$firstPostId][] = (string) $id;
            }
        }

        $this->io->info(sprintf('Found %d threads.', count($threads)));

        foreach ($threads as $posts) {
            $content = [];
            foreach ($posts as $postId) {
                $content[] = $statuses[$postId]['content'];
            }
            $html = implode("\n\n", $content);

            file_put_contents($this->cacheDir.'/post_'.$posts[0].'.html', $html);
        }

        return 0;
    }
}
