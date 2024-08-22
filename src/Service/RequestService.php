<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A service for mapping requests to ZGW cases.
 *
 * @author Wilco Louwerse <wilco@conduction.nl>
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 */
class RequestService
{


    /**
     * @param GatewayResourceService $gatewayResourceService The resource Service.
     * @param CallService $callService The call Service.
     * @param MappingService $mappingService The mapping service.
     * @param LoggerInterface $pluginLogger The logger interface.
     */
    public function __construct(
        private readonly GatewayResourceService $gatewayResourceService,
        private readonly CallService $callService,
        private readonly MappingService $mappingService,
        private readonly LoggerInterface $pluginLogger,
    ) {

    }//end __construct()


    /**
     * Creates a document in the Source.
     *
     * @param Source $source The source to request.
     * @param array $document The array containing information to create a document.
     *
     * @return array The response of the api-call to the source after creating a document.
     */
    private function createDocument(Source $source, array $document): array
    {
        if (isset($document['file']) === true) {
            $document['file'] = base64_decode($document['file']);
        }
        
        $response = $this->callService->call(source: $source, endpoint: '/api/documents', method: 'POST',
            config: [
                'body' => json_encode($document),
            ]
        );

        return $this->callService->decodeResponse(source: $source, response: $response);

    }//end createDocument()


    /**
     * Creates a Request in the Source.
     *
     * @param Source $source The source to request.
     * @param array $body The array containing information to create a request.
     *
     * @return void
     */
    private function createRequest(Source $source, array $body): void
    {
        $this->callService->call(source: $source, endpoint: '/api/requests', method: 'POST',
            config: [
                'body' => json_encode($body)
            ]
        );

    }//end createRequest()


    /**
     * Creates cases from externally fetched requests.
     *
     * @param array $data          The data in the request.
     * @param array $configuration The configuration for this handler.
     *
     * @return array The request data, updated with the cases.
     */
    public function createRequestHandler(array $data, array $configuration): array
    {
        $source = $this->gatewayResourceService->getSource(reference: $configuration['source'], pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        $mapping = $this->gatewayResourceService->getMapping(reference: $configuration['mapping'], pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        if ($source === null || $mapping === null) {
            $message = 'Could not find a Source & Mapping for ' . $configuration['source'] . ' & ' . $configuration['mapping'];
            $this->pluginLogger->error(message: $message, context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']);
            $data['response'] = new Response(\Safe\json_encode(['Message' => $message]), 500, ['Content-type' => 'application/json']);
            
            return $data;
        }
        
        $body = $this->mappingService->mapping(mappingObject: $mapping, input: $data['body']);
        
        foreach ($body['documents'] as $key => $document) {
            $body['documents'][$key] = $this->createDocument(source: $source, document: $document)['contentUrl'];
        }
        
        $this->createRequest(source: $source, body: $body);

        return $data;

    }//end createRequestHandler()


}//end class
