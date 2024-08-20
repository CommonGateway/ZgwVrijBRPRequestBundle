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
class ZaakService
{


    /**
     * @param EntityManagerInterface $entityManager The Entity Manager.
     * @param LoggerInterface        $pluginLogger  The plugin version of the logger interface.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $pluginLogger,
        private readonly GatewayResourceService $gatewayResourceService,
        private readonly CallService $callService,
        private readonly MappingService $mappingService,
        private readonly CacheService $cacheService,
    ) {

    }//end __construct()


    /**
     * Flatten references within a json schema.
     *
     * @param  array $object The object to flatten.
     * @param  array $base   The base object to flatten.
     * @return array
     */
    public function flattenJsonSchema(array $object, array $base=[]): array
    {
        if ($base === []) {
            $base = $object;
        }

        foreach ($object as $key => $value) {
            if (is_array(value: $value) === true) {
                $object[$key] = $this->flattenJsonSchema(object: $value, base: $base);
            } else if ($key === '$ref') {
                $ref = explode(separator: '/', string: $value);

                array_shift(array: $ref);
                $referenced = $base;

                foreach ($ref as $item) {
                    $referenced = $referenced[$item];
                }

                $referenced = $this->flattenJsonSchema(object: $referenced, base: $base);

                $object = array_merge($object, $referenced);
                unset($object['$ref']);
            }
        }

        return $object;

    }//end flattenJsonSchema()


    /**
     * Fetch request from the source.
     *
     * @param string $sourceReference The source to request.
     *
     * @return array The resulting request types.
     */
    public function getRequests(string $sourceReference): array
    {
        $source = $this->gatewayResourceService->getSource(reference: $sourceReference, pluginName:'common-gateway/zgw-vrijbrp-request-bundle');

        $response = $this->callService->call(source: $source, endpoint: '/api/requests');

        return $this->callService->decodeResponse(source: $source, response: $response)['hydra:member'];

    }//end getRequestTypes()


    /**
     * Fetch an existing case from the database, or create a new one.
     *
     * @param string $code The identification code for the case.
     *
     * @return ObjectEntity The request (empty or filled)
     */
    public function getCase(string $code): ObjectEntity
    {
        $schema = $this->gatewayResourceService->getSchema(
            reference: 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json',
            pluginName: 'common-gateway/zgw-vrijbrp-request-bundle'
        );

        $filters = [
            '_self.schema.ref' => $schema->getReference(),
            'identificatie'    => $code,
        ];

        $objects = $this->cacheService->retrieveObjectsFromCache(filter: $filters, options: []);

        if ($objects['total'] === 0) {
            return new ObjectEntity(entity: $schema);
        }

        $id     = $objects['results'][0]['_id'];
        $object = $this->entityManager->getRepository(class: ObjectEntity::class)->find(id: $id);

        if ($object !== null) {
            return $object;
        }

        return new ObjectEntity(entity: $schema);

    }//end getCase()


    /**
     * Hydrate a case from the request.
     *
     * @param array  $request      The request from the source.
     * @param string $mappingReference The mapping reference of the correct mapping.
     *
     * @return ObjectEntity The resulting case.
     */
    public function hydrateCase(array $request, string $mappingReference): ObjectEntity
    {
        $mapping = $this->gatewayResourceService->getMapping(reference: $mappingReference, pluginName:'common-gateway/zgw-vrijbrp-request-bundle');

        $request['schema'] = $this->flattenJsonSchema(object: $request['schema']);
        $caseArray         = $this->mappingService->mapping(mappingObject: $mapping, input: $request);

        $case = $this->getCase(code: $caseArray['identificatie']);
        $case->hydrate($caseArray);

        $this->entityManager->persist($case);
        $this->entityManager->flush();

        return $case;

    }//end hydrateCase()


    /**
     * Maps the requests to cases and stores them.
     *
     * @param array  $requests     The requests from the source
     * @param string $mappingReference The mapping reference for the mapping to map the request to a case
     *
     * @return array The resulting cases.
     */
    public function hydrateCases(array $requests, string $mappingReference): array
    {
        $hydratedCases = [];

        foreach ($requests as $request) {
            $hydratedCases[] = $this->hydrateCase(request: $request, mappingReference: $mappingReference);
        }

        return $hydratedCases;

    }//end hydrateCases()


    /**
     * Creates cases from externally fetched requests.
     *
     * @param array $data          The data in the request.
     * @param array $configuration The configuration for this handler.
     *
     * @return array The request data, updated with the cases.
     */
    public function syncCaseHandler(array $data, array $configuration): array
    {
        $mappingReference = $configuration['mapping'];
        $sourceReference  = $configuration['source'];

        $requests = $this->getRequests(sourceReference: $sourceReference);

        $data['cases'] = $this->hydrateCases(requests: $requests, mappingReference: $mappingReference);

        return $data;

    }//end syncCaseHandler()


}//end class
