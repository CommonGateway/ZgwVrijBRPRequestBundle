<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

use Adbar\Dot;
use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use App\Entity\Synchronization;
use App\Event\ActionEvent;
use App\Service\SynchronizationService;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\MappingService;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use finfo;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;

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
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;


    /**
     * @param GatewayResourceService   $gatewayResourceService The resource Service.
     * @param CallService              $callService            The call Service.
     * @param MappingService           $mappingService         The mapping service.
     * @param LoggerInterface          $pluginLogger           The logger interface.
     * @param CacheService             $cacheService           The cache service.
     * @param EventDispatcherInterface $eventDispatcher        The event dispatcher.
     * @param SynchronizationService   $syncService            The Gateway synchronization service.
     * @param EntityManagerInterface   $entityManager          The Entity Manager.
     */
    public function __construct(
        private readonly GatewayResourceService $gatewayResourceService,
        private readonly CallService $callService,
        private readonly MappingService $mappingService,
        private readonly LoggerInterface $pluginLogger,
        private readonly CacheService $cacheService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SynchronizationService $syncService,
        private readonly EntityManagerInterface $entityManager,
    ) {

    }//end __construct()


    /**
     * Set symfony style for command output during cronjob:command.
     *
     * @param SymfonyStyle $style Symfony style.
     *
     * @return void
     */
    public function setStyle(SymfonyStyle $style): void
    {
        $this->style = $style;

    }//end setStyle()


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
            [
                '_self.synchronizations'          => 'IS NULL',
                'embedded.zaaktype.identificatie' => ['like' => 'vrijbrp-'],
                '_self.dateCreated'               => ['before' => $beforeDateTime->format(format: 'Y-m-d H:i:s')],
            ],
            [$configuration['schema']]
        );

        if (isset($this->style) === true) {
            $this->style->section('checkCasesHandler');
            $this->style->writeln('Found '.count($result['results']).' Cases to create Requests for.');
        }

        // Loop through results and start creating Requests.
        foreach ($result['results'] as $zaak) {
            // Throw (async) event for creating a Request for this Case.
            $event = new ActionEvent('commongateway.action.event', ['body' => $zaak], 'vrijbrp.caseToRequest.sync');
            $this->eventDispatcher->dispatch($event, 'commongateway.action.event');
        }

        // Todo: Check / get cases for zaaktype identificatie in ['B0328', 'B0255', 'B0348', 'B1425', 'B0237', 'B0337', 'B0360', 'B0366']
        // (first 4 are from NaamgebruikVrijBRPBundle, last 4 are from GeboorteVrijBRPBundle)
        // Todo: FirstRegistration might work differently? documents.0.zaak.zaaktype.identificatie in ['B333', 'B334']
        // Todo: throw event for "vrijbrp.zaak.created" for other 9 e-diensten.
        return $data;

    }//end checkCasesHandler()


    /**
     * Generates a filename like "file.pdf" by combining 'file' and the extension we can get from the file $binaryData.
     *
     * @param string $binaryData The binary data of a file, to get the mime type from, and with that the extension.
     *
     * @return string|null A file name or null.
     */
    private function generateFilename(string $binaryData): ?string
    {
        // Use finfo to detect the MIME type
        $finfo    = new finfo(flags: FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer(string: $binaryData);

        // Instantiate the MimeTypes class
        $mimeTypes = new MimeTypes();

        // Get the file extensions associated with the MIME type
        $extensions = $mimeTypes->getExtensions(mimeType: $mimeType);

        if (empty($extensions) === true) {
            $this->pluginLogger->error(
                message: 'Could not Create Document. $document in createDocument() has no "filename"
                    and could not find a file extension for MIME type: '.$mimeType.' in generateFilename().',
                context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']
            );

            return null;
        }

        return 'file.'.$extensions[0];

    }//end generateFilename()


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
        if (isset($document['file']) === false) {
            $this->pluginLogger->error(
                message: 'Could not Create Document. $document in createDocument expects a key "file".',
                context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']
            );

            return [];
        }

        $document['file'] = base64_decode(string: $document['file']);

        if (empty($document['filename']) === true) {
            $document['filename'] = $this->generateFilename(binaryData: $document['file']);
            if (empty($document['filename']) === null) {
                return [];
            }
        }

        try {
            $response = $this->callService->call(
                source: $source,
                endpoint: '/api/documents',
                method: 'POST',
                config: [
                    'multipart' => [
                        [
                            'name'     => 'file',
                            'contents' => $document['file'],
                            'filename' => $document['filename'],
                        ],
                    ],
                ]
            );
        } catch (Exception | GuzzleException $exception) {
            if (method_exists(object_or_class: get_class(object: $exception), method: 'getResponse') === true && $exception->getResponse() !== null) {
                $responseBody = $exception->getResponse()->getBody();
            }

            $this->pluginLogger->error(
                message: 'Could not Create Document. Error message: '.$exception->getMessage().'\nFull Response: '.($responseBody ?? ''),
                context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']
            );

            return [];
        }//end try

        return $this->callService->decodeResponse(source: $source, response: $response);

    }//end createDocument()


    /**
     * Temporary function as replacement of the $this->syncService->synchronize() function.
     * Because currently synchronize function can only pull from a source and not push to a source.
     * // Todo: temp way of doing this without updated synchronize() function...
     *
     * @param Synchronization $synchronization The synchronization we are going to synchronize.
     * @param array           $objectArray     The object data we are going to synchronize.
     *
     * @return array The response body of the outgoing call, or an empty array on error.
     */
    public function synchronizeTemp(Synchronization $synchronization, array $objectArray, string $location): array
    {
        $objectString = $this->syncService->getObjectString($objectArray);

        $this->pluginLogger->info(
            message: 'Sending message with body '.$objectString,
            context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']
        );

        try {
            $result = $this->callService->call(
                $synchronization->getSource(),
                $location,
                'POST',
                [
                    'body'    => $objectString,
                    'headers' => $synchronization->getSource()->getHeaders(),
                ]
            );
        } catch (Exception | GuzzleException $exception) {
            $this->syncService->ioCatchException(
                $exception,
                [
                    'line',
                    'file',
                    'message' => ['preMessage' => 'Error while doing syncToSource in zgwToVrijbrpHandler: '],
                ]
            );
            if (method_exists(get_class($exception), 'getResponse') === true && $exception->getResponse() !== null) {
                $responseBody = $exception->getResponse()->getBody();
            }

            $this->pluginLogger->error(
                message: 'Could not synchronize object. Error message: '.$exception->getMessage().'\nFull Response: '.($responseBody ?? ''),
                context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']
            );

            return [];
        }//end try

        $body = $this->callService->decodeResponse($synchronization->getSource(), $result);

        $bodyDot = new Dot($body);
        $now     = new DateTime();
        $synchronization->setLastSynced($now);
        $synchronization->setSourceLastChanged($now);
        $synchronization->setLastChecked($now);
        $synchronization->setHash(hash('sha384', serialize($bodyDot->jsonSerialize())));

        return $body;

    }//end synchronizeTemp()


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
        $object = $this->entityManager->getRepository('App:ObjectEntity')->find($data['body']['_id']);

        // Make sure we only do this for zaaktype starting with "vrijbrp-"
        if ($object === null || isset($data['body']['embedded']['zaaktype']['identificatie']) === false
            || str_starts_with(haystack: $data['body']['embedded']['zaaktype']['identificatie'], needle: "vrijbrp-") === false
        ) {
            $message = 'Could not find an object with id '.$data['body']['_id'].' or zaaktype identificatie does not start with "vrijbrp-"';
            $this->pluginLogger->error(message: $message, context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']);
            $data['response'] = new Response(\Safe\json_encode(['Message' => $message]), 500, ['Content-type' => 'application/json']);

            return $data;
        }

        // Get the Source and Mapping.
        $source  = $this->gatewayResourceService->getSource(reference: $configuration['source'], pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        $mapping = $this->gatewayResourceService->getMapping(reference: $configuration['mapping'], pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        $schema  = $this->gatewayResourceService->getSchema(reference: $configuration['schema'], pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        if ($source === null || $mapping === null || $schema === null) {
            $message = 'Could not find a Source, Mapping or Schema for '.$configuration['source'].', '.$configuration['mapping'].' or https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json';
            $this->pluginLogger->error(message: $message, context: ['plugin' => 'common-gateway/zgw-vrijbrp-request-bundle']);
            $data['response'] = new Response(\Safe\json_encode(['Message' => $message]), 500, ['Content-type' => 'application/json']);

            return $data;
        }

        // Get full body of this Case Object.
        $zaak = $object->toArray(['embedded' => true]);

        // Mapping, incl documents = [{file = zaakinformatieobject.informatieobject.inhoud}]
        $requestBody = $this->mappingService->mapping(mappingObject: $mapping, input: $zaak);

        // Handle documents (zaakinformatieobjecten) for this Case.
        foreach ($requestBody['documents'] as $key => $document) {
            // Todo: we could create synchronizations for these documents as well
            $requestBody['documents'][$key] = $this->createDocument(source: $source, document: $document)['@id'];
        }

        // Create synchronization & sync.
        $synchronization  = $this->syncService->findSyncByObject(objectEntity: $object, source: $source, entity: $schema);
        $response         = $this->synchronizeTemp(synchronization: $synchronization, objectArray: $requestBody, location: '/api/requests');
        $data['response'] = new Response(\Safe\json_encode($response), 200, ['Content-type' => 'application/json']);

        $this->entityManager->persist($synchronization);
        $this->entityManager->flush();
        $this->cacheService->cacheObject(objectEntity: $synchronization->getObject());

        return $data;

    }//end createRequestHandler()


}//end class
