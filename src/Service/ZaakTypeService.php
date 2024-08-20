<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\ObjectEntity;
use CommonGateway\CoreBundle\Service\CacheService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\HydrationService;
use CommonGateway\CoreBundle\Service\MappingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * An example service for adding business logic to your class.
 *
 * @author Conduction.nl <info@conduction.nl>
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 */
class ZaakTypeService
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
        private readonly HydrationService $hydrationService,
    ) {

    }//end __construct()


    /**
     * Flatten references within a json schema.
     *
     * @param  array $object The object to flatten.
     * @param  array $base   The base object to flatten.
     * @return array
     */
    public function flattenJsonSchema(array $object, array $base=[])
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
     * Fetch request types from the source.
     *
     * @param string $source The source to request.
     *
     * @return array The resulting request types.
     */
    public function getRequestTypes(Source $source): array
    {
        $response = $this->callService->call(source: $source, endpoint: '/api/request_types');

        return $this->callService->decodeResponse(source: $source, response: $response)['hydra:member'];

    }//end getRequestTypes()


    /**
     * Hydrate a case type from the request type.
     *
     * @param array  $requestType      The request type from the source.
     * @param string $mappingReference The mapping reference of the correct mapping.
     *
     * @return ObjectEntity The resulting case type.
     */
    public function hydrateCaseType(array $requestType, string $mappingReference, Source $source): ObjectEntity
    {
        $mapping = $this->gatewayResourceService->getMapping(
            reference: $mappingReference,
            pluginName:'common-gateway/zgw-vrijbrp-request-bundle');
        $schema  = $this->gatewayResourceService->getSchema(
            reference: 'https://vng.opencatalogi.nl/schemas/ztc.zaakType.schema.json',
            pluginName: 'common-gateway/zgw-vrijbrp-request-bundle'
        );

        $requestType['schema'] = $this->flattenJsonSchema(object: $requestType['schema']);
        $caseTypeArray         = $this->mappingService->mapping(mappingObject: $mapping, input: $requestType);

        $caseType = $this->hydrationService->searchAndReplaceSynchronizations(object: $caseTypeArray, source: $source, entity: $schema);

        return $caseType;

    }//end hydrateCaseType()


    /**
     * Maps the request types to case types and stores them.
     *
     * @param array  $requestTypes     The request types from the source
     * @param string $mappingReference The mapping reference for the mapping to map the request type to a case type
     *
     * @return array The resulting case types.
     */
    public function hydrateCaseTypes(array $requestTypes, string $mappingReference, Source $source): array
    {
        $hydratedCaseTypes = [];

        foreach ($requestTypes as $requestType) {
            $hydratedCaseTypes[] = $this->hydrateCaseType(requestType: $requestType, mappingReference: $mappingReference, source: $source);
        }

        return $hydratedCaseTypes;

    }//end hydrateCaseTypes()


    /**
     * Creates case types from externally fetched request types
     *
     * @param array $data          The data in the request.
     * @param array $configuration The configuration for this handler.
     *
     * @return array The request data, updated with the case types.
     */
    public function syncCaseTypeHandler(array $data, array $configuration): array
    {
        $mappingReference = $configuration['mapping'];
        $sourceReference  = $configuration['source'];

        $source = $this->gatewayResourceService->getSource(reference: $sourceReference, pluginName:'common-gateway/zgw-vrijbrp-request-bundle');

        $requestTypes = $this->getRequestTypes(source: $source);

        $data['caseTypes'] = $this->hydrateCaseTypes(requestTypes: $requestTypes, mappingReference: $mappingReference, source: $source);

        return $data;

    }//end syncCaseTypeHandler()


}//end class
