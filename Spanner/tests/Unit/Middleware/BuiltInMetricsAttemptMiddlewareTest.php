<?php
/**
 * Copyright 2026 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Spanner\Tests\Unit\Middleware;

use Google\ApiCore\Call;
use Google\Cloud\Spanner\Middleware\BuiltInMetricsAttemptMiddleware;
use GuzzleHttp\Promise\FulfilledPromise;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * @group spanner
 */
class BuiltInMetricsAttemptMiddlewareTest extends TestCase
{
    use ProphecyTrait;

    private $meter;
    private $histogram;
    private $counter;
    private $nextHandler;

    public function setUp(): void
    {
        $this->histogram = $this->prophesize(HistogramInterface::class);
        $this->counter = $this->prophesize(CounterInterface::class);
        $this->meter = $this->prophesize(MeterInterface::class);

        $this->meter->createHistogram(
            'attempt_latencies',
            'ms',
            Argument::any()
        )->willReturn($this->histogram->reveal());

        $this->meter->createCounter(
            'attempt_count',
            '1',
            Argument::any()
        )->willReturn($this->counter->reveal());

        $this->nextHandler = function ($call, $options) {
            return new FulfilledPromise('ok');
        };
    }

    public function testRecordsAttemptMetrics()
    {
        $projectId = 'test-project';
        $clientId = 'test-client-id';
        $clientName = 'php-spanner/1.0.0';

        $middleware = new BuiltInMetricsAttemptMiddleware(
            $this->nextHandler,
            $this->meter->reveal(),
            $clientId,
            $projectId,
            $clientName
        );

        $call = $this->prophesize(Call::class);
        $call->getMethod()->willReturn('Commit');

        $options = [
            'resource-prefix' => 'projects/p/instances/i/databases/d'
        ];

        // Verify Labels
        $expectedLabels = [
            'method' => 'Commit',
            'status' => 'OK',
            'instance_id' => 'i',
            'database' => 'd',
            'project_id' => $projectId,
            'client_uid' => $clientId,
            'client_name' => $clientName,
            'instance_config' => 'unknown',
            'location' => 'global'
        ];

        $this->counter->add(1, Argument::that(function ($labels) use ($expectedLabels) {
            return $labels == $expectedLabels;
        }))->shouldBeCalled();
        
        $this->histogram->record(Argument::type('float'), Argument::that(function ($labels) use ($expectedLabels) {
            return $labels == $expectedLabels;
        }))->shouldBeCalled();

        $promise = $middleware($call->reveal(), $options);
        $promise->wait();
    }
}
