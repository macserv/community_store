<?php
namespace Concrete\Package\CommunityStore\Src\CommunityStore\Product;

use Pagerfanta\Adapter\DoctrineDbalAdapter;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Search\Pagination\Pagination;
use Concrete\Core\Search\ItemList\Database\AttributedItemList;
use Concrete\Core\Search\Pagination\PaginationProviderInterface;
use Concrete\Package\CommunityStore\Entity\Attribute\Key\StoreProductKey;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product as StoreProduct;
use Concrete\Package\CommunityStore\Src\CommunityStore\Report\ProductReport as StoreProductReport;

class ProductList extends AttributedItemList implements PaginationProviderInterface
{
    protected $gIDs = [];
    protected $groupMatchAny = false;
    protected $sortBy = "alpha";
    protected $randomSeed = '';
    protected $sortByDirection = "desc";
    protected $featuredOnly = false;
    protected $saleOnly = false;
    protected $activeOnly = true;
    protected $cIDs = [];
    protected $relatedProduct = false;
    protected $manufacturer = '';
    protected $attFilters = [];

    public function setGroupID($gID)
    {
        $this->gIDs = [$gID];
    }

    public function setGroupIDs($groupIDs)
    {
        $this->gIDs = array_merge($this->gIDs, $groupIDs);
    }

    public function setSortBy($sort)
    {
        $this->sortBy = $sort;
    }

    public function setRandomSeed($seed = '')
    {
        $this->randomSeed = $seed;
    }

    public function setSortByDirection($dir)
    {
        $this->sortByDirection = $dir;
    }

    public function getSortByDirection()
    {
        return $this->sortByDirection;
    }

    public function setCID($cID)
    {
        $this->cIDs[] = $cID;
    }

    public function setCIDs($cIDs)
    {
        $this->cIDs = array_merge($this->cIDs, array_values($cIDs));
    }

    public function setGroupMatchAny($match)
    {
        $this->groupMatchAny = (bool) $match;
    }

    public function setFeaturedOnly($bool)
    {
        $this->featuredOnly = $bool;
    }

    public function setManufacturer($manufacturer)
    {
        $this->manufacturer = $manufacturer;
    }

    public function setSaleOnly($bool)
    {
        $this->saleOnly = $bool;
    }

    public function setActiveOnly($bool)
    {
        $this->activeOnly = $bool;
    }

    public function setShowOutOfStock($bool)
    {
        $this->showOutOfStock = $bool;
    }

    public function setRelatedProduct($product)
    {
        $this->relatedProduct = $product;
    }

    public function setAttributeFilters($filterArray)
    {
        $this->attFilters = $filterArray;
        $app = Application::getFacadeApplication();
        $productCategory = $app->make('Concrete\Package\CommunityStore\Attribute\Category\ProductCategory');

        if (!empty($this->attFilters)) {
            foreach ($this->attFilters as $handle => $value) {
                if ('price' == $handle) {
                    $this->filterByPrice($value);
                } else {
                    $ak = $productCategory->getByHandle($handle);

                    if (is_object($ak)) {
                        $ak->getController()->filterByAttribute($this, $value);
                    }
                }
            }
        }
    }

    public function processUrlFilters(\Concrete\Core\Http\Request $request)
    {
        $service = Application::getFacadeApplication()->make('helper/security');
        $querystring = $request->getQueryString();

        $params = explode('&', $querystring);

        $searchparams = [];

        foreach ($params as $param) {
            $values = explode('=', $param);
            $handle = str_replace('%5B%5D', '', $values[0]);
            $handle = $service->sanitizeString($handle);

            $type = 'or';

            if (strpos($values[1], '%3B')) {
                $type = 'and';
            }

            $value = str_replace('%7C', '%2C', $values[1]);
            $value = str_replace('%3B', '%2C', $value);

            $value = str_replace('%20', ' ', $value);
            $value = $service->sanitizeString($value);
            $values = explode('%2C', $value);

            foreach ($values as $val) {
                if ($val) {
                    $searchparams[$handle]['type'] = $type;
                    $searchparams[$handle]['values'][] = urldecode($val);
                }
            }
        }


        $app = Application::getFacadeApplication();
        $productCategory = $app->make('Concrete\Package\CommunityStore\Attribute\Category\ProductCategory');

        foreach ($searchparams as $handle => $searchvalue) {
            $type = $searchvalue['type'];
            $value = $searchvalue['values'];

            if ('price' == $handle) {
                $this->filterByPrice($value[0]);
            } else {
                $ak = $productCategory->getByHandle($handle);

                if (is_object($ak)) {
                    $value = array_filter($value);
                    if ('boolean' == $ak->getAttributeType()->getAttributeTypeHandle()) {
                        $this->getQueryObject()->andWhere('ak_' . $handle . ' = ' . $value[0]);
                    } else {
                        if ('and' == $type) {
                            foreach ($value as $searchterm) {
                                $this->getQueryObject()->andWhere('ak_' . $handle . ' REGEXP "(^|\n)' . $searchterm . '($|\n)"');
                            }
                        } else {
                            $this->getQueryObject()->andWhere('ak_' . $handle . ' REGEXP "(^|\n)' . implode('($|\n)|(^|\n)', $value ) . '($|\n)"');
                        }
                    }
                }
            }
        }
    }

    public function filterByPrice($pricestring)
    {
        $items = explode('-', $pricestring);

        if (count($items) > 1) {
            $this->getQueryObject()->andWhere('pPrice <= ' . (float) $items[1]);
            $this->getQueryObject()->andWhere('pPrice >= ' . (float) $items[0]);
        } elseif (isset($items[0])) {  // if single price, treat as max
            $this->getQueryObject()->andWhere('pPrice <= ' . (float) $items[0]);
        }
    }

    protected function getAttributeKeyClassName()
    {
        return StoreProductKey::class;
    }

    public function createQuery()
    {
        $this->query
        ->select('p.pID')
        ->from('CommunityStoreProducts', 'p');
    }

    public function setSearch($search)
    {
        $this->search = $search;
    }

    public function finalizeQuery(\Doctrine\DBAL\Query\QueryBuilder $query)
    {
        $paramcount = 0;

        if (!empty($this->gIDs)) {
            $validgids = [];

            foreach ($this->gIDs as $gID) {
                if ($gID > 0) {
                    $validgids[] = $gID;
                }
            }

            if (!empty($validgids)) {
                $query->innerJoin('p', 'CommunityStoreProductGroups', 'g', 'p.pID = g.pID and g.gID in (' . implode(',', $validgids) . ')');

                if (!$this->groupMatchAny) {
                    $query->having('count(g.gID) = ' . count($validgids));
                }
            }
        }

        $relatedids = [];

        // if we have a true value for related, we don't have an object, meaning it couldn't find a product to look for related products for
        // this means we should return no products
        if (true === $this->relatedProduct) {
            $query->andWhere("1 = 0");
        } elseif (is_object($this->relatedProduct)) {
            $related = $this->relatedProduct->getRelatedProducts();

            foreach ($related as $r) {
                $relatedids[] = $r->getRelatedProductID();
            }

            if (!empty($relatedids)) {
                $query->andWhere('p.pID in (' . implode(',', $relatedids) . ')');
            } else {
                $query->andWhere('1 = 0');
            }
        } elseif (is_array($this->cIDs) && !empty($this->cIDs)) {
            $query->innerJoin('p', 'CommunityStoreProductLocations', 'l', 'p.pID = l.pID and l.cID in (' . implode(',', $this->cIDs) . ')');
        }

        switch ($this->sortBy) {
            case "alpha":
                $query->orderBy('pName', $this->getSortByDirection());
                break;
            case "alpha_asc":
                $query->orderBy('pName', 'asc');
                break;
            case "alpha_desc":
                $query->orderBy('pName', 'desc');
                break;
            case "price":
                $query->orderBy('pPrice', $this->getSortByDirection());
                break;
            case "price_asc":
                $query->orderBy('pPrice', 'asc');
                break;
            case "price_desc":
                $query->orderBy('pPrice', 'desc');
                break;
            case "active":
                $query->orderBy('pActive', $this->getSortByDirection());
                break;
            case "date":
                $query->orderBy('pDateAdded', $this->getSortByDirection());
                break;
            case "popular":
                $pr = new StoreProductReport();
                $pr->sortByPopularity();
                $products = $pr->getProducts();
                $pIDs = [];
                foreach ($products as $product) {
                    $pIDs[] = $product['pID'];
                }

                foreach ($pIDs as $pID) {
                    $query->addOrderBy("p.pID = ?", 'DESC')->setParameter($paramcount++, $pID);
                }
                break;
            case "related":
                if (!empty($relatedids)) {
                    $query->addOrderBy('FIELD (p.pID, ' . implode(',', $relatedids) . ')');
                }
                break;
            case "category":
                $query->addOrderBy('categorySortOrder');
                break;
            case "random":
                $query->orderBy('RAND(' . $this->randomSeed . ')', null); break;
                break;
        }
        if ($this->featuredOnly) {
            $query->andWhere("pFeatured = 1");
        }
        if ($this->manufacturer) {
            $query->andWhere("pManufacturer = ?")->setParameter($paramcount++, $this->manufacturer);
        }
        if ($this->saleOnly) {
            $query->andWhere("pSalePrice is not null");
        }
        if (!$this->showOutOfStock) {
            $query->andWhere("pQty > 0 OR pQtyUnlim = 1");
        }
        if ($this->activeOnly) {
            $query->andWhere("pActive = 1");
        }

        if ($this->sortBy == 'category') {
            $query->groupBy('p.pID, p.pName, p.pPrice, p.pActive, p.pDateAdded, categorySortOrder');
        } else {
            $query->groupBy('p.pID, p.pName, p.pPrice, p.pActive, p.pDateAdded');
        }

        if ($this->search) {
            $query->andWhere('pName like ?')->setParameter($paramcount++, '%' . $this->search . '%')->orWhere('pSKU like ?')->setParameter($paramcount++, '%' . $this->search . '%');
        }

        $query->leftJoin('p', 'CommunityStoreProductSearchIndexAttributes', 'csi', 'p.pID = csi.pID');

        return $query;
    }

    public function getResult($queryRow)
    {
        return StoreProduct::getByID($queryRow['pID']);
    }

    protected function createPaginationObject()
    {
        $adapter = new DoctrineDbalAdapter($this->deliverQueryObject(), function ($query) {
            $values = $query->execute()->fetchAll();
            $count = count($values);

            $query->resetQueryParts(['groupBy', 'orderBy', 'having', 'join', 'where', 'from'])->from('DUAL')->select($count . ' c ');
        });
        $pagination = new Pagination($this, $adapter);

        return $pagination;
    }

    public function getPaginationAdapter()
    {
        $adapter = new DoctrineDbalAdapter($this->deliverQueryObject(), function ($query) {
            $query->resetQueryParts(['groupBy', 'orderBy'])->select('count(distinct p.pID)')->setMaxResults(1);
        });

        return $adapter;
    }

    public function getTotalResults()
    {
        $query = $this->deliverQueryObject();
        $values = $query->execute()->fetchAll();
        $count = count($values);

        return $query->resetQueryParts(['groupBy', 'orderBy', 'having', 'join', 'where', 'from'])->from('DUAL')->select($count)->setMaxResults(1)->execute()->fetchColumn();
    }

    public function getResultIDs()
    {
        $query = $this->deliverQueryObject();
        $values = $query->execute()->fetchAll();

        $productids = [];

        foreach ($values as $val) {
            $productids[] = $val['pID'];
        }

        return $productids;
    }
}
