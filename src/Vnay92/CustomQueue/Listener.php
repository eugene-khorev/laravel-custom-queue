<?php
/*
    Copyright 2018 Vinay Bharadwaj

    Permission is hereby granted, free of charge, to any person obtaining a copy of this software
    and associated documentation files (the "Software"), to deal in the Software without restriction,
    including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
    and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so,
    subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all
    copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED,
    INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
    IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
    WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE
    OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace Vnay92\CustomQueue;

use Closure;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessUtils;
use Symfony\Component\Process\PhpExecutableFinder;

class Listener
{
    /**
     * The command working path.
     *
     * @var string
     */
    protected $commandPath;

    /**
     * The environment the workers should run under.
     *
     * @var string
     */
    protected $environment;

    /**
     * The amount of seconds to wait before polling the queue.
     *
     * @var int
     */
    protected $sleep = 3;

    /**
     * The amount of times to try a job before logging it failed.
     *
     * @var int
     */
    protected $maxTries = 0;

    /**
     * The queue worker command line.
     *
     * @var string
     */
    protected $workerCommand;

    /**
     * The output handler callback.
     *
     * @var \Closure|null
     */
    protected $outputHandler;

    /**
     * Create a new queue listener.
     *
     * @param  string  $commandPath
     * @return void
     */
    public function __construct($commandPath)
    {
        $this->commandPath = $commandPath;
        $this->workerCommand = $this->buildWorkerCommand();
    }

    /**
     * Build the environment specific worker command.
     *
     * @return string
     */
    protected function buildWorkerCommand()
    {
        $binary = (new PhpExecutableFinder)->find(false);

        if (defined('HHVM_VERSION')) {
            $binary .= ' --php';
        }

        if (defined('ARTISAN_BINARY')) {
            $artisan = ARTISAN_BINARY;
        } else {
            $artisan = 'artisan';
        }

        $command = 'custom-queue:work %s --queue=%s --delay=%s --memory=%s --sleep=%s --tries=%s';

        return "{$binary} {$artisan} {$command}";
    }

    /**
     * Listen to the given queue connection.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $delay
     * @param  string  $memory
     * @param  int     $timeout
     * @return void
     */
    public function listen($connection, $queue, $delay, $memory, $timeout = 60)
    {
        $process = $this->makeProcess($connection, $queue, $delay, $memory, $timeout);

        while (true) {
            $this->runProcess($process, $memory);
        }
    }

    /**
     * Run the given process.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @param  int  $memory
     * @return void
     */
    public function runProcess(Process $process, $memory)
    {
        $process->run(function ($type, $line) {
            $this->handleWorkerOutput($type, $line);
        });

        // Once we have run the job we'll go check if the memory limit has been
        // exceeded for the script. If it has, we will kill this script so a
        // process manager will restart this with a clean slate of memory.
        if ($this->memoryExceeded($memory)) {
            $this->stop();
        }
    }

    /**
     * Create a new Symfony process for the worker.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  int     $delay
     * @param  int     $memory
     * @param  int     $timeout
     * @return \Symfony\Component\Process\Process
     */
    public function makeProcess($connection, $queue, $delay, $memory, $timeout)
    {
        $string = $this->workerCommand;

        // If the environment is set, we will append it to the command string so the
        // workers will run under the specified environment. Otherwise, they will
        // just run under the production environment which is not always right.
        if (isset($this->environment)) {
            $string .= ' --env='.ProcessUtils::escapeArgument($this->environment);
        }

        // Next, we will just format out the worker commands with all of the various
        // options available for the command. This will produce the final command
        // line that we will pass into a Symfony process object for processing.
        $command = sprintf(
            $string,
            $connection,
            $queue,
            $delay,
            $memory,
            $this->sleep,
            $this->maxTries
        );

        return new Process($command, $this->commandPath, null, null, $timeout);
    }

    /**
     * Handle output from the worker process.
     *
     * @param  int  $type
     * @param  string  $line
     * @return void
     */
    protected function handleWorkerOutput($type, $line)
    {
        if (isset($this->outputHandler)) {
            call_user_func($this->outputHandler, $type, $line);
        }
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @param  int  $memoryLimit
     * @return bool
     */
    public function memoryExceeded($memoryLimit)
    {
        return (memory_get_usage() / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Stop listening and bail out of the script.
     *
     * @return void
     */
    public function stop()
    {
        die;
    }

    /**
     * Set the output handler callback.
     *
     * @param  \Closure  $outputHandler
     * @return void
     */
    public function setOutputHandler(Closure $outputHandler)
    {
        $this->outputHandler = $outputHandler;
    }

    /**
     * Get the current listener environment.
     *
     * @return string
     */
    public function getEnvironment()
    {
        return $this->environment;
    }

    /**
     * Set the current environment.
     *
     * @param  string  $environment
     * @return void
     */
    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * Get the amount of seconds to wait before polling the queue.
     *
     * @return int
     */
    public function getSleep()
    {
        return $this->sleep;
    }

    /**
     * Set the amount of seconds to wait before polling the queue.
     *
     * @param  int  $sleep
     * @return void
     */
    public function setSleep($sleep)
    {
        $this->sleep = $sleep;
    }

    /**
     * Set the amount of times to try a job before logging it failed.
     *
     * @param  int  $tries
     * @return void
     */
    public function setMaxTries($tries)
    {
        $this->maxTries = $tries;
    }
}
