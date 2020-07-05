<?php


namespace Efrogg\ContentRenderer\Connector\Squidex\NodeProvider;


use Efrogg\ContentRenderer\Asset\AssetDataManagerInterface;
use Efrogg\ContentRenderer\Connector\ConnectorInterface;
use Efrogg\ContentRenderer\Connector\Squidex\Asset\SquidexAsset;
use Efrogg\ContentRenderer\Connector\Squidex\SquidexConnector;
use Efrogg\ContentRenderer\Connector\Squidex\SquidexTools;
use Efrogg\ContentRenderer\Converter\Keyword;
use Efrogg\ContentRenderer\Decorator\DecoratorAwareTrait;
use Efrogg\ContentRenderer\Exception\NodeNotFoundException;
use Efrogg\ContentRenderer\Node;
use Efrogg\ContentRenderer\NodeProvider\NodeProviderInterface;
use Exception;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Cache\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class SquidexNodeProvider implements NodeProviderInterface
{
    use DecoratorAwareTrait;

    /**
     * @var ConnectorInterface
     */
    private $connector;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var AssetDataManagerInterface
     */
    private $assetManager;

    /**
     * @var int
     */
    private $TTL=1800;


    public function __construct(SquidexConnector $connector)
    {
        $this->connector = $connector;
    }

    public function canResolve($solvable, string $resolverName): bool
    {
        return SquidexTools::isNodeId($solvable);
    }

    public function setAcceptUnpublished(bool $accept=true):void
    {
        $this->connector->setAcceptUnpublished($accept);
    }

    /**
     * @param  string  $nodeId
     * @return Node
     * @throws NodeNotFoundException
     * @throws BadResponseException
     * @throws InvalidArgumentException
     */
    public function getNodeById(string $nodeId): Node
    {
        if(null !== $this->cache) {
            $rawNodeData = $this->cache->get($nodeId,function(ItemInterface $item) {
                $item->expiresAfter($this->getTTL());
//                $item->expiresAfter(new \DateInterval('P1D'));
//                $item->expiresAt(new \DateTime('11:00'));
                return $this->getRawNodeData($item->getKey());
            });
        } else {
            $rawNodeData = $this->getRawNodeData($nodeId);
        }

        $nodeData = $this->convertData($rawNodeData['data']);
        return new Node($nodeData, $rawNodeData['context']);
    }

    /**
     * @param  string  $nodeId
     * @return array
     * @throws NodeNotFoundException
     * @throws BadResponseException
     */
    private function getRawNodeData(string $nodeId): array
    {
        $node = $this->connector->getNodeById($nodeId);
        return [
            'data'    => array_merge(
                [Keyword::NODE_TYPE => $node->getSchemaName()],
                $node->getData()
            ),
            'context' => $node->getContext()
        ];
    }

    private function convertData(array $data):array
    {
//        dump($data);
        $converted = [];
        foreach ($data as $k => $datum) {
            try {
                $converted[$k] = $this->convertOneField($datum);
            } catch (Exception $exception) {
                dd('no data for '.$k,$exception);
                $converted[$k] = null;
                // node not found ?
            }
        }
//        dump($converted);
        return $converted;
    }

    private function convertOneField($datum, $tryNode = true)
    {
        if (is_array($datum) && isset($datum['iv'])) {
            $iv = $datum['iv'];
        } else {
            $iv = $datum;
        }

        if (is_string($iv)) {
            return $this->decorate($iv);
        }

        if (is_array($iv)) {
            $children = [];
            foreach ($iv as $key => $oneIv) {
                if (is_string($oneIv) && SquidexTools::isNodeId($oneIv)) {
                    // commence par les assets, car les nodes ont un fallback vers l'api => call inutile si c'est une asset
                    if ($asset = $this->getAsset($oneIv)) {
                        // on a trouv� un asset
                        $children[$key] = $asset;
                    } else {
                        // on cherche un node
                        try {
                            // on a bien le node
                            $children[$key] = $this->getNodeById($oneIv);
                        } catch (NodeNotFoundException $exception) {
                            // ce n'est pas une asset r�f�renc�e, ni un node
                            // on retourne un asset sans infos, juste le nom
                            return new SquidexAsset($oneIv);
                        }
                    }
                } else {
                    $children[$key] = $this->convertOneField($oneIv, false);
//                        dd("iv inconnu",$oneIv);
                }
            }
            return $children;
        }

        return $iv;
    }

    private function getAsset(string $oneIv): ?SquidexAsset
    {
        if(null !== $this->assetManager) {
            if($assetData = $this->assetManager->getAsset($oneIv)) {
                //TODO : on peut passer d'autres infos du assetData (isImage, type ....)
                return new SquidexAsset($oneIv,$assetData['version']);
            }
            // non trouv�e, on fait quoi ?
            // return null => ce ne sera pas une asset (image vide)
            // si on passe => asset sans version (et si ce n'est pas une asset ?)
            return null;
        }

        // m�me sans le cache, on peut consid�rer que c'est une image ...
//        dd("no asset for id ".$oneIv);
        return new SquidexAsset($oneIv);
    }

    /**
     * @return CacheInterface
     */
    public function getCache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * @param  CacheInterface  $cache
     */
    public function setCache(CacheInterface $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * @return int
     */
    public function getTTL(): int
    {
        return $this->TTL;
    }

    /**
     * @param  int  $TTL
     */
    public function setTTL(int $TTL): void
    {
        $this->TTL = $TTL;
    }

    /**
     * @return AssetDataManagerInterface
     */
    public function getAssetManager(): AssetDataManagerInterface
    {
        return $this->assetManager;
    }

    /**
     * @param  AssetDataManagerInterface  $assetManager
     */
    public function setAssetManager(AssetDataManagerInterface $assetManager): void
    {
        $this->assetManager = $assetManager;
    }

}