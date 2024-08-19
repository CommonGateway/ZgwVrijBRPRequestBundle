<?php

namespace CommonGateway\ZgwVrijBRPRequestBundle\Service;

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
        private readonly GatewayResourceService $gatewayResourceService
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
            if (is_array($value) === true) {
                $object[$key] = $this->flattenJsonSchema($value, $base);
            } else if ($key === '$ref') {
                $ref = explode('/', $value);

                array_shift($ref);
                $referenced = $base;

                foreach ($ref as $item) {
                    $referenced = $referenced[$item];
                }

                $referenced = $this->flattenJsonSchema($referenced, $base);

                $object = array_merge($object, $referenced);
                unset($object['$ref']);
            }
        }

        return $object;

    }//end flattenJsonSchema()


    /**
     * @param  string $source
     * @return array
     */
    public function getRequestTypes(string $source): array
    {

    }//end getRequestTypes()


}//end class
