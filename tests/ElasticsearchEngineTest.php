<?php

use Laravel\Scout\Builder;
use Illuminate\Database\Eloquent\Collection;
use ScoutEngines\Elasticsearch\ElasticsearchEngine;

class ElasticsearchEngineTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Mockery::close();
    }

    public function test_update_adds_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('bulk')->with([
            'body' => [
                [
                    'update' => [
                        '_id'    => 1,
                        '_index' => 'scout',
                        '_type'  => 'table',
                    ],
                ],
                [
                    'doc'           => ['id' => 1],
                    'doc_as_upsert' => true,
                ],
            ],
        ]);

        $engine = new ElasticsearchEngine($client, 'scout');
        $engine->update(Collection::make([new ElasticsearchEngineTestModel]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('bulk')->with([
            'body' => [
                [
                    'delete' => [
                        '_id'    => 1,
                        '_index' => 'scout',
                        '_type'  => 'table',
                    ],
                ],
            ],
        ]);

        $engine = new ElasticsearchEngine($client, 'scout');
        $engine->delete(Collection::make([new ElasticsearchEngineTestModel]));
    }

    public function test_search_sends_correct_parameters_to_elasticsearch()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('search')->with([
            'index' => 'scout',
            'type'  => 'table',
            'body'  => [
                'query' => [
                    'bool' => [
                        'must'   => [
                            'function_score' => [
                                'query' => [
                                    'multi_match' => [
                                        'query'     => 'zonda',
                                        'fuzziness' => 'auto',
                                        'operator'  => 'or',
                                    ],
                                ],
                            ],
                        ],
                        'filter' => [
                            ['match_phrase' => ['foo' => 1]],
                            ['terms' => ['bar' => [1, 3]]],
                        ],
                    ],
                ],
                'sort'  => [
                    ['id' => 'desc'],
                ],
            ],
        ]);

        $engine = new ElasticsearchEngine($client, 'scout');
        $builder = new Laravel\Scout\Builder(new ElasticsearchEngineTestModel, 'zonda');
        $builder->where('foo', 1);
        $builder->where('bar', [1, 3]);
        $builder->orderBy('id', 'desc');
        $engine->search($builder);
    }

    public function test_model_can_specify_fields_to_search()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('search')->with([
            'index' => 'scout',
            'type'  => 'table',
            'body'  => [
                'query' => [
                    'bool' => [
                        'must'   => [
                            'function_score' => [
                                'query' => [
                                    'multi_match' => [
                                        'query'     => 'zonda',
                                        'fuzziness' => 'auto',
                                        'operator'  => 'or',
                                        'fields'    => ['id'],
                                    ],
                                ],
                            ],
                        ],
                        'filter' => [],
                    ],
                ],
            ],
        ]);

        $engine = new ElasticsearchEngine($client, 'scout');
        $builder = new Laravel\Scout\Builder(new ElasticsearchEngineTestModelWithSearchableFields, 'zonda');
        $engine->search($builder);
    }

    public function test_builder_callback_can_manipulate_search_parameters_to_elasticsearch()
    {
        /** @var \Elasticsearch\Client|\Mockery\MockInterface $client */
        $client = Mockery::mock(\Elasticsearch\Client::class);
        $client->shouldReceive('search')->with('modified_by_callback');

        $engine = new ElasticsearchEngine($client, 'scout');
        $builder = new Laravel\Scout\Builder(
            new ElasticsearchEngineTestModel(),
            'huayra',
            function (\Elasticsearch\Client $client, $query, $params) {
                $this->assertNotEmpty($params);
                $this->assertEquals('huayra', $query);
                $params = 'modified_by_callback';

                return $client->search($params);
            }
        );

        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $engine = new ElasticsearchEngine($client, 'scout');

        $builder = Mockery::mock(Builder::class);

        $model = Mockery::mock('Illuminate\Database\Eloquent\Model');
        $model->shouldReceive('getScoutKey')->andReturn('1');
        $model->shouldReceive('getScoutModelsByIds')->once()->with($builder,
            ['1'])->andReturn($models = Collection::make([$model]));
        $model->shouldReceive('newCollection')->andReturn($models);

        $results = $engine->map($builder, [
            'hits' => [
                'total' => '1',
                'hits'  => [
                    [
                        '_id' => '1',
                    ],
                ],
            ],
        ], $model);

        $this->assertEquals(1, count($results));
    }
}

class ElasticsearchEngineTestModel extends \Illuminate\Database\Eloquent\Model
{
    public function getIdAttribute()
    {
        return 1;
    }

    public function searchableAs()
    {
        return 'table';
    }

    public function getKey()
    {
        return '1';
    }

    public function toSearchableArray()
    {
        return ['id' => 1];
    }

    public function scoutMetadata()
    {
        return [];
    }
}

class ElasticsearchEngineTestModelWithSearchableFields extends \Illuminate\Database\Eloquent\Model
{
    public function getIdAttribute()
    {
        return 1;
    }

    public function searchableAs()
    {
        return 'table';
    }

    public function toSearchableArray()
    {
        return ['id' => 1];
    }    public function getKey()
    {
        return '1';
    }

    public function scoutMetadata()
    {
        return [];
    }

    public function searchableFields()
    {
        return [
            'id',
        ];
    }


}
