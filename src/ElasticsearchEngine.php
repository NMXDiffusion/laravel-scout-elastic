<?php

namespace ScoutEngines\Elasticsearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;

class ElasticsearchEngine extends Engine
{
    /**
     * Index where the models will be saved.
     *
     * @var string
     */
    protected $index;

    /**
     * Elastic where the instance of Elastic|\Elasticsearch\Client is stored.
     *
     * @var object
     */
    protected $elastic;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Create a new engine instance.
     *
     * @param \Elasticsearch\Client $elastic
     * @param string                $index
     * @param bool                  $softDelete
     */
    public function __construct(Elastic $elastic, $index, $softDelete = false)
    {
        $this->elastic = $elastic;
        $this->index = $index;
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param Collection $models
     * @return void
     */
    public function update($models)
    {
        $params['body'] = [];

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $models->each(function ($model) use (&$params) {
            $params['body'][] = [
                'update' => [
                    '_id'    => $model->getKey(),
                    '_index' => $this->index,
                    '_type'  => $model->searchableAs(),
                ],
            ];
            $params['body'][] = [
                'doc'           => array_merge($model->toSearchableArray(), $model->scoutMetadata()),
                'doc_as_upsert' => true,
            ];
        });

        $this->elastic->bulk($params);
    }

    /**
     * Remove the given model from the index.
     *
     * @param Collection $models
     * @return void
     */
    public function delete($models)
    {
        $params['body'] = [];

        $models->each(function ($model) use (&$params) {
            $params['body'][] = [
                'delete' => [
                    '_id'    => $model->getKey(),
                    '_index' => $this->index,
                    '_type'  => $model->searchableAs(),
                ],
            ];
        });

        $this->elastic->bulk($params);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'size'           => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param int     $perPage
     * @param int     $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'from'           => (($page * $perPage) - $perPage),
            'size'           => $perPage,
        ]);

        $result['nbPages'] = $result['hits']['total'] / $perPage;

        return $result;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param mixed $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param \Laravel\Scout\Builder              $builder
     * @param mixed                               $results
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results['hits']['total'] === 0) {
            return $model->newCollection();
        }

        $keys = collect($results['hits']['hits'])->pluck('_id')->values()->all();
        $keysPotition = array_flip($keys);

        return $model->getScoutModelsByIds(
            $builder, $keys
        )->filter(function ($model) use ($keys) {
            return in_array($model->getScoutKey(), $keys);
        })->sortBy(function ($model) use ($keysPotition) {
            return $keysPotition[$model->getScoutKey()];
        })->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param mixed $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public function flush($model)
    {
        $model->newQuery()
            ->orderBy($model->getKeyName())
            ->unsearchable();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param Builder $builder
     * @param array   $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $this->index,
            'type'  => $builder->index ?: $builder->model->searchableAs(),
            'body'  => [
                'query' => [
                    'bool' => [
                        'must'   => [
                            'multi_match' => [
                                'query'     => $builder->query,
                                'fuzziness' => 'auto',
                                'operator'  => 'and',
                            ],
                        ],
                        'filter' => [],
                    ],
                ],
            ],
        ];

        if (! $builder->query) {
            unset($params['body']['query']['bool']['must']);
        }

        if ($builder->query && method_exists($builder->model, 'searchableFields')) {
            data_set(
                $params,
                'body.query.bool.must.multi_match.fields',
                $builder->model->searchableFields()
            );
        }

        if ($sort = $this->sort($builder)) {
            $params['body']['sort'] = $sort;
        }

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }

        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['filter'] = array_merge(
                $params['body']['query']['bool']['filter'], $options['numericFilters']
            );
        }

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->elastic,
                $builder->query,
                $params
            );
        }

        return $this->elastic->search($params);
    }

    /**
     * Get the filter array for the query.
     *
     * @param Builder $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            if (is_array($value)) {
                return ['terms' => ["{$key}.keyword" => $value]];
            }

            return ['match_phrase' => [$key => $value]];
        })->values()->all();
    }

    /**
     * Generates the sort if theres any.
     *
     * @param Builder $builder
     * @return array|null
     */
    protected function sort($builder)
    {
        if (count($builder->orders) == 0) {
            return null;
        }

        return collect($builder->orders)->map(function ($order) {
            return [$order['column'] => $order['direction']];
        })->toArray();
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
