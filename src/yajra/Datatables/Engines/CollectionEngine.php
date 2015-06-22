<?php

namespace yajra\Datatables\Engines;

/**
 * Laravel Datatables Collection Engine
 *
 * @package  Laravel
 * @category Package
 * @author   Arjay Angeles <aqangeles@gmail.com>
 */

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use yajra\Datatables\Contracts\DataTableEngine;
use yajra\Datatables\Request;

class CollectionEngine extends BaseEngine implements DataTableEngine
{

    /**
     * Collection object
     *
     * @var Collection
     */
    public $collection;

    /**
     * Collection object
     *
     * @var Collection
     */
    public $original_collection;

    /**
     * @param Collection $collection
     * @param \yajra\Datatables\Request $request
     */
    public function __construct(Collection $collection, Request $request)
    {
        $this->request             = $request;
        $this->collection          = $collection;
        $this->original_collection = $collection;
        $this->columns             = array_keys($this->serialize($collection->first()));
    }

    /**
     * Serialize collection
     *
     * @param  mixed $collection
     * @return mixed|null
     */
    protected function serialize($collection)
    {
        return $collection instanceof Arrayable ? $collection->toArray() : $collection;
    }

    /**
     * @inheritdoc
     */
    public function ordering()
    {
        foreach ($this->request->orderableColumns() as $orderable) {
            $column           = $this->getColumnName($orderable['column']);
            $this->collection = $this->collection->sortBy(
                function ($row) use ($column) {
                    return $row[$column];
                }
            );

            if ($orderable['direction'] == 'desc') {
                $this->collection = $this->collection->reverse();
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function filtering()
    {
        $columns          = $this->request['columns'];
        $this->collection = $this->collection->filter(
            function ($row) use ($columns) {
                $data  = $this->serialize($row);
                $found = [];

                $keyword = $this->request->keyword();
                foreach ($this->request->searchableColumnIndex() as $index) {
                    $column = $this->getColumnName($index);

                    if ( ! array_key_exists($column, $data)) {
                        continue;
                    }

                    if ($this->isCaseInsensitive()) {
                        $found[] = Str::contains(Str::lower($data[$column]), Str::lower($keyword));
                    } else {
                        $found[] = Str::contains($data[$column], $keyword);
                    }
                }

                return in_array(true, $found);
            }
        );
    }

    /**
     * @inheritdoc
     */
    public function columnSearch()
    {
        $columns = $this->request->get('columns');
        for ($i = 0, $c = count($columns); $i < $c; $i++) {
            if ($this->request->isColumnSearchable($i)) {
                $column  = $this->getColumnIdentity($i);
                $keyword = $this->request->columnKeyword($i);

                $this->collection = $this->collection->filter(
                    function ($row) use ($column, $keyword) {
                        $data = $this->serialize($row);
                        if ($this->isCaseInsensitive()) {
                            return strpos(Str::lower($data[$column]), Str::lower($keyword)) !== false;
                        } else {
                            return strpos($data[$column], $keyword) !== false;
                        }
                    }
                );
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function count()
    {
        return $this->collection->count();
    }

    /**
     * @inheritdoc
     */
    public function setResults()
    {
        $this->result_object = $this->collection->all();

        return $this->result_array = array_map(
            function ($object) {
                return $object instanceof Arrayable ? $object->toArray() : (array) $object;
            }, $this->result_object
        );
    }

    /**
     * @inheritdoc
     */
    public function filter(Closure $callback)
    {
        $this->autoFilter = false;

        call_user_func($callback, $this);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function paging()
    {
        if ($this->request->isPaginationable()) {
            $this->collection = $this->collection->slice(
                $this->request['start'],
                (int) $this->request['length'] > 0 ? $this->request['length'] : 10
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function showDebugger(array $output)
    {
        $output["input"] = $this->request->all();

        return $output;
    }

    /**
     * Organizes works.
     *
     * @param bool $mDataSupport
     * @return JsonResponse
     */
    public function make($mDataSupport = false)
    {
        $this->m_data_support = $mDataSupport;
        $this->totalRecords = $this->count();
        $this->ordering();

        if ($this->autoFilter && $this->request->isSearchable()) {
            $this->filtering();
        }
        $this->columnSearch();
        $this->filteredRecords = $this->count();

        $this->paging();
        $this->setResults();
        $this->initColumns();
        $this->regulateArray();

        return $this->output();

    }
}
