<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Event\ActionEvent;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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
     * @param LoggerInterface        $pluginLogger           The logger interface.
     * @param CacheService           $cacheService           The cache service.
     * @param EventDispatcherInterface $eventDispatcher     The event dispatcher.
     */
    public function __construct(
        private readonly GatewayResourceService $gatewayResourceService,
        private readonly CallService $callService,
        private readonly MappingService $mappingService,
        private readonly LoggerInterface $pluginLogger,
        private readonly CacheService $cacheService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {

    }//end __construct()


    /**
     * Creates a document in the Source.
     *
     * @param Source $source   The source to request.
     * @param array  $document The array containing information to create a document.
     *
     * @return array The response of the api-call to the source after creating a document.
     */
    private function createDocument(Source $source, array $document): array
    {
        if (isset($document['file']) === true) {
            $document['file'] = base64_decode($document['file']);
        }

        $response = $this->callService->call(
            source: $source,
            endpoint: '/api/documents',
            method: 'POST',
            config: [
                'body' => json_encode($document),
                'headers' => [
                    'Accept' => 'multipart/form-data'
                ]
            ]
        );

        return $this->callService->decodeResponse(source: $source, response: $response);

    }//end createDocument()


    /**
     * Creates a Request in the Source.
     *
     * @param Source $source The source to request.
     * @param array  $body   The array containing information to create a request.
     *
     * @return void
     */
    private function createRequest(Source $source, array $body): void
    {
        $this->callService->call(
            source: $source,
            endpoint: '/api/requests',
            method: 'POST',
            config: [
                'body' => json_encode($body),
            ]
        );

    }//end createRequest()


    /**
     * Creates request from a case.
     *
     * @param array $data          The data in the request.
     * @param array $configuration The configuration for this handler.
     *
     * @return array The request data.
     */
    public function createRequestHandler(array $data, array $configuration): array
    {
        $zaak = $this->cacheService->getObject($data['body']['_id']);

        // Make sure we only do this for zaaktype starting with "vrijbrp-"
        if ($zaak === null || isset($zaak['embedded']['zaaktype']['identificatie']) === false
            || str_starts_with(haystack: $zaak['embedded']['zaaktype']['identificatie'], needle: "vrijbrp-") === false
        ) {
            return $data;
        }

        // Get the Source and Mapping.
        $source  = $this->gatewayResourceService->getSource(reference: $configuration['source'], pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        $mapping = $this->gatewayResourceService->getMapping(reference: $configuration['mapping'], pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        if ($source === null || $mapping === null) {
            $message = 'Could not find a Source & Mapping for '.$configuration['source'].' & '.$configuration['mapping'];
            $this->pluginLogger->error(message: $message, context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']);
            $data['response'] = new Response(\Safe\json_encode(['Message' => $message]), 500, ['Content-type' => 'application/json']);

            return $data;
        }
        
        //Todo: create Sync...

        // Mapping, incl documents = [{file = zaakinformatieobject.informatieobject.inhoud}]
        $requestBody = $this->mappingService->mapping(mappingObject: $mapping, input: $zaak);

        // Handle documents (zaakinformatieobjecten) for this Case.
        foreach ($requestBody['documents'] as $key => $document) {
            $requestBody['documents'][$key] = $this->createDocument(source: $source, document: $document)['contentUrl'];
        }

        $this->createRequest(source: $source, body: $requestBody);

        return $data;

    }//end createRequestHandler()
    
    
    /**
     * Checks if there are Cases we need to create a Request for.
     *
     * @param array $data          The data in the request.
     * @param array $configuration The configuration for this handler.
     *
     * @return array The request data.
     */
    public function checkCasesHandler(array $data, array $configuration): array
    {
        // Create the DateTime object for 10 minutes ago.
        $beforeDateTime = (new DateTime())->modify(modifier: $configuration['beforeTimeModifier']);
        
        // Search all cases we should create Requests for.
        $result = $this->cacheService->searchObjects(
            filter: [
                '_self.synchronizations' => 'IS NULL',
                'embedded.zaaktype.identificatie' => ['like' => 'vrijbrp-'],
                '_self.dateCreated' => ['before' => $beforeDateTime->format(format: 'Y-m-d H:i:s')]
            ],
            entities: ['https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json']
        );
        
        // Loop through results and start creating Requests.
        foreach ($result['results'] as $zaak) {
            // Throw (async) event for creating a Request for this Case.
            $event = new ActionEvent('commongateway.action.event', ['body' => $zaak], 'vrijbrp.caseToRequest.sync');
            $this->eventDispatcher->dispatch($event, 'commongateway.action.event');
        }
        
        return $data;
    }


}//end class
