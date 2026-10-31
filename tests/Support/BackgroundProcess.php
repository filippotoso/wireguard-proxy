<?php

declare(strict_types=1);

namespace FilippoToso\WireguardProxy\Tests\Support;

final class BackgroundProcess
{
    /** @var resource|null */
    private $process = null;

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public function start(array $command, array $env, string $logFile): void
    {
        $fullEnv = [];
        foreach (getenv() as $key => $value) {
            $fullEnv[$key] = $value;
        }
        foreach ($env as $key => $value) {
            $fullEnv[$key] = $value;
        }

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
            null,
            $fullEnv,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start process: ' . implode(' ', $command));
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $this->process = $process;
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        proc_terminate($this->process);
        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if ($status['running'] === false) {
                proc_close($this->process);
                $this->process = null;

                return;
            }
            usleep(20_000);
        }

        proc_terminate($this->process, 9);
        proc_close($this->process);
        $this->process = null;
    }
}
