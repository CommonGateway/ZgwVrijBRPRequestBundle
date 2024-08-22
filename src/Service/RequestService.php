<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

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
     * @param CallService            $callService            The call Service.
     * @param MappingService         $mappingService         The mapping service.
     */
    public function __construct(
        private readonly GatewayResourceService $gatewayResourceService,
        private readonly CallService $callService,
        private readonly MappingService $mappingService,
    ) {

    }//end __construct()


    /**
     * Creates a document in the Source.
     *
     * @param string $sourceReference  The source to request.
     * @param string $mappingReference The mapping reference of the correct mapping.
     * @param array  $document         The array containing information to create a document.
     *
     * @return array The response of the api-call to the source after creating a document.
     */
    private function createDocument(string $sourceReference, string $mappingReference, array $document): array
    {
        $source  = $this->gatewayResourceService->getSource(reference: $sourceReference, pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        $mapping = $this->gatewayResourceService->getMapping(reference: $mappingReference, pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        if ($source === null || $mapping === null) {
            // todo:
            return [];
        }

        $document = $this->mappingService->mapping(mappingObject: $mapping, input: $document);

        $response = $this->callService->call(
            source: $source,
            endpoint: '/api/documents',
            method: 'POST',
            config: [
                'body' => json_encode($document),
            ]
        );

        return $this->callService->decodeResponse(source: $source, response: $response);

    }//end createDocument()


    /**
     * Creates a Request in the Source.
     *
     * @param string $sourceReference The source to request.
     * @param array  $data            The array containing information to create a request.
     *
     * @return void
     */
    private function createRequest(string $sourceReference, array $data): void
    {
        $source = $this->gatewayResourceService->getSource(reference: $sourceReference, pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        if ($source === null) {
            // todo:
            return;
        }

        $this->callService->call(
            source: $source,
            endpoint: '/api/requests',
            method: 'POST',
            config: [
                'body' => json_encode($data),
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
        $sourceReference  = $configuration['source'];
        $mappingReference = $configuration['mapping'];

        foreach ($data['documents'] as $key => $document) {
            $data['documents'][$key] = $this->createDocument(
                sourceReference: $sourceReference,
                mappingReference: $mappingReference,
                document: $document
            )['contentUrl'];
        }

        $this->createRequest(sourceReference: $sourceReference, data: $data);

        return $data;

    }//end createRequestHandler()


}//end class
