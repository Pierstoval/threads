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
        $this->projectDir = dirname(__DIR__);
        $this->cacheDir = $this->projectDir.'/cache';
        $io = new SymfonyStyle($input, $output);

        $useCache = !$input->getOption('no-cache');
        $accountName = $input->getArgument('account_name');
        $minimumThreadSize = (int) $input->getOption('minimum-thread-size');

        try {
            $this->doRun($useCache, $io, $accountName, $minimumThreadSize);
        } catch (\Throwable $e) {
            do {
                $io->error([
                    $e::class,
                    $e->getMessage(),
                    $e->getTraceAsString(),
                ]);
            } while ($e = $e->getPrevious());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function doRun(bool $useCache, SymfonyStyle $io, mixed $accountName, int $minimumThreadSize): void
    {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created', $this->cacheDir));
        }

        $this->fetchCache($useCache);
        $this->loadEnv();
        $this->createClient();

        $io->title('Running Threads');

        $progress = new ProgressIndicator($io, indicatorChangeInterval: 10, indicatorValues: ['🌑', '🌒', '🌓', '🌔', '🌕', '🌖', '🌗', '🌘']);
        $progress->start('Fetching statuses from Mastodon...');
        $statuses = $this->fetchStatuses(
            $accountName,
            progressAdvance: static function (string $message) use ($progress) {
                $progress->setMessage($message);
                $progress->advance();
            },
            progressFinish: static fn(string $message) => $progress->finish($message),
        );

        $io->info('Number of statuses: ' . count($statuses));

        $tree = $this->getThreadsTree($statuses, $minimumThreadSize);
        $threads = $this->getThreadsStatusesFromTree($tree);

        $io->info(sprintf('Found %d threads.', count($threads)));

        $this->saveThreadsToCache($threads, $statuses);
    }

    private function fetchCache(bool $useCache = true): void
    {
        $this->cachedDataFile = $this->cacheDir . '/cached_data.json';
        $this->cachedData = [];
        if (\is_file($this->cachedDataFile) && $useCache) {
            $this->cachedData = json_decode(file_get_contents($this->cachedDataFile), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    private function loadEnv(): void
    {
        $envFile = $this->projectDir . '/.env';
        if (!is_file($envFile)) {
            throw new \RuntimeException(sprintf(
                'Env file "%s" does not exist. You can create it by copy/pasting the "%s" file.',
                $envFile,
                $envFile . '.example',
            ));
        }

        $env = new Dotenv();
        $env->loadEnv($envFile);

        $keys = [
            'APP_INSTANCE',
            'APP_ACCESS_TOKEN',
        ];

        foreach ($keys as $key) {
            if (!isset($_ENV[$key])) {
                throw new \RuntimeException(\sprintf('Environment key "%s" is not set. Please make sure all the variables are set in the "%s" file.' . "\n" . 'List of variables: %s', 'APP_INSTANCE', $envFile, implode(', ', $keys)));
            }
        }
    }

    private function createClient(): void
    {
        $factory = new ApiClientFactory();
        $this->client = $factory->build();
        $this->client->setBaseUri('https://' . $_ENV['APP_INSTANCE']);
        $this->client->setAccessToken($_ENV['APP_ACCESS_TOKEN']);
    }

    private function fetchStatuses(string $accountName, ?\Closure $progressAdvance = null, ?\Closure $progressFinish = null): array
    {
        $progressAdvance = $progressAdvance ?: static fn () => null;
        $progressFinish = $progressFinish ?: static fn () => null;

        $accountId = $this->client->methods()->accounts()->lookup($accountName)->id;

        $statuses = $this->cachedData['statuses'] ?? [];
        $lastId = $this->cachedData['last_id'] ?? null;
        $actualLastId = null;

        while (true) {
            $progressAdvance('Statuses found: ' . \count($statuses));
            if ($lastId && $actualLastId === $lastId) {
                $progressFinish('Apparently found enough posts');
                break;
            }
            $actualLastId = $lastId;
            try {
                /** @var StatusModel[] $newStatuses */
                $newStatuses = $this->client->methods()->accounts()->statuses(
                    id: $accountId,
                    max_id: $lastId,
                    limit: 40,
                )->all();
                if (!count($newStatuses)) {
                    $progressFinish(' No more statuses to check');
                    break;
                }

                foreach ($newStatuses as $status) {
                    $progressAdvance('Statuses found: ' . \count($statuses));
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
                        $progressAdvance('Statuses found: ' . \count($statuses));
                        $store[$key] = $status->$key;
                    }
                    $statuses[$status->id] = $store;
                }
            } catch (TooManyRequestsException $e) {
                $progressFinish(\sprintf('Too many requests after id "%s".', $lastId));
                break;
            }
        }
        try {
            $progressFinish('');
        } catch (\Throwable) {
        }

        $this->cachedData['last_id'] = $lastId;
        $this->cachedData['statuses'] = $statuses;

        file_put_contents($this->cachedDataFile, json_encode($this->cachedData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $statuses;
    }

    private function getThreadsTree(array $statuses, int $minimumThreadSize): array
    {
        $tree = [];

        foreach ($statuses as $accountId => $status) {
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

            $tree[$accountId] = $parents;
        }

        return $tree;
    }

    private function getThreadsStatusesFromTree(array $tree): array
    {
        $threads = [];
        foreach ($tree as $accountId => $items) {
            $firstPostId = array_last($items);
            if (isset($threads[$firstPostId])) {
                $thread = $threads[$firstPostId];
                if (count($items) > count($thread)) {
                    $threads[$firstPostId] = array_reverse($items);
                    $threads[$firstPostId][] = (string)$accountId;
                }
            } else {
                $threads[$firstPostId] = array_reverse($items);
                $threads[$firstPostId][] = (string)$accountId;
            }
        }
        return $threads;
    }

    private function saveThreadsToCache(array $threads, array $statuses): void
    {
        foreach ($threads as $posts) {
            $content = [];
            foreach ($posts as $postId) {
                $content[] = $statuses[$postId]['content'];
            }
            $html = implode("\n\n", $content);

            file_put_contents($this->cacheDir . '/post_' . $posts[0] . '.html', $html);
        }
    }
}
