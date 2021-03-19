<?php


namespace Efrogg\ContentRenderer\Connector\Storyblok\NodeProvider;


use Efrogg\ContentRenderer\Connector\Storyblok\Asset\StoryBlokAsset;
use Efrogg\ContentRenderer\Connector\Storyblok\Lib\ApiException;
use Efrogg\ContentRenderer\Connector\Storyblok\Lib\Client;
use Efrogg\ContentRenderer\Converter\Keyword;
use Efrogg\ContentRenderer\Decorator\DecoratorAwareTrait;
use Efrogg\ContentRenderer\Log\LoggerProxy;
use Efrogg\ContentRenderer\Node;
use Efrogg\ContentRenderer\NodeProvider\NodeProviderInterface;
use Psr\Log\LoggerInterface;
use Storyblok\RichtextRender\Resolver;
use Ubaldi\Cms\Cache\NodeRelationCacheManager;

class StoryBlokNodeProvider implements NodeProviderInterface
{
    use DecoratorAwareTrait;
    use LoggerProxy;

    public const KEY_UID = '_uid';
    public const KEY_FILENAME = 'filename';
    public const KEY_COMPONENT = 'component';
    public const KEY_IMAGE_ID = 'id';
    public const PROVIDER_IDENTIFIER = 'StoryBlok';
    /**
     * @var NodeRelationCacheManager
     */
    protected $nodeRelationCacheManager;

    /**
     * @var Client
     */
    private $client;
    /**
     * @var Resolver
     */
    private $textResolver;

    public function __construct(array $apiKeys, ?LoggerInterface $logger = null)
    {
        $this->client = new Client($apiKeys['preview']);
        $this->client->setTimeout(5);
        // pour le rendu  des RichText
        $this->textResolver = new Resolver();
        $this->initLogger($logger);
    }

    /**
     * @param NodeRelationCacheManager $nodeRelationCacheManager
     * @return StoryBlokNodeProvider
     */
    public function setNodeRelationCacheManager(NodeRelationCacheManager $nodeRelationCacheManager
    ): StoryBlokNodeProvider {
        $this->nodeRelationCacheManager = $nodeRelationCacheManager;
        return $this;
    }

    public function getNodeById(string $nodeId): Node
    {
        return $this->convertStoryDataToNode($this->client->responseBody['story']);
    }

    public function canResolve($solvable, string $resolverName): bool
    {
        try {
            $this->info(
                'load ' . $solvable,
                ['title' => 'StoryBlokNodeProvider']
            );
            $this->client->getStoryBySlug('cms/' . $solvable);

            if(isset($this->nodeRelationCacheManager)) {
                // on d�sactive la gestion des relations ici
                $this->nodeRelationCacheManager->setActive(false);
            }

            return true;
        } catch (ApiException $e) {
//            $this->error('error : ' . $e->getMessage(),['code'=>$e->getCode()]);
            return false;
        }
    }

    private function convertStoryDataToNode(array $storyData): Node
    {
        $this->info('convert data', ['data' => $storyData, 'title' => 'StoryBlokNodeProvider']);
        return $this->convertDataToNode($storyData['content']);
    }

    private function convertDataToNode(array $content): Node
    {
        $context = [];
        $nodeData = [
            '__cmsProvider__'        => self::PROVIDER_IDENTIFIER,
            '__storyBlokHotReload__' => isset($_GET['_storyblok_version'])
        ];
        foreach ($content as $key => $value) {
            switch ($key) {
                case self::KEY_UID:
                    $nodeData[Keyword::NODE_ID] = $value;
                    break;
                case self::KEY_COMPONENT:
                    $nodeData[Keyword::NODE_TYPE] = $value;
                    break;
                default:
                    $nodeData[$key] = $this->convertValue($value);
            }
        }

        return new Node($nodeData, $context);
    }

    /**
     * retourne true si on a affaire � une liste de nodes imbriqu�s
     * @param $nested
     * @return bool
     */
    private function isNestedNodeArray($nested): bool
    {
        if (!is_array($nested) || empty($nested)) {
            return false;
        }

        foreach ($nested as $key => $value) {
            if (!is_numeric($key) || !is_array($value) || !isset($value[self::KEY_UID])) {
                return false;
            }
        }

        return true;
    }

    private function isAssetArray($nested): bool
    {
        return is_array($nested) && isset($nested[0][self::KEY_IMAGE_ID], $nested[0][self::KEY_FILENAME]);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function convertValue($value)
    {
        if ($this->isNestedNodeArray($value)) {
            $newArray = [];
            foreach ($value as $nodeKey => $nodeData) {
                $newArray[$nodeKey] = $this->convertDataToNode($nodeData);
            }
            return $newArray;
        }

        if ($this->isAssetArray($value)) {
            $assets = [];
            foreach ($value as $nodeKey => $assetData) {
                $assets [$nodeKey] = new StoryBlokAsset($assetData);
            }
            return $assets;
        }

        if (is_array($value)) {
            if (isset($value['type']) && $value['type'] === 'doc') {
                return $this->decorate($this->textResolver->render($value));
            }

//            if(isset($value['fieldtype']) && $value['fieldtype'] === 'asset') {
            if (isset($value[self::KEY_FILENAME], $value[self::KEY_IMAGE_ID])) {
                $value[Keyword::NODE_ID] = $value['id'];
                return new StoryBlokAsset($value);
            }

            if (isset($_GET['debug']) && EST_EFROGG_VRAI === true) {
                dump('unknown array data : ', $value);
            }
        }

        if (is_string($value)) {
//            dump($value." : ".$this->decorate($value));
            return $this->decorate($value);
        }

        // autre ?
        return $value;
    }

}