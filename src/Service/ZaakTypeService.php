<?php

namespace CommonGateway\PetStoreBundle\Service;

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
     * The configuration array.
     *
     * @var array
     */
    private array $configuration;

    /**
     * The data array.
     *
     * @var array
     */
    private array $data;

    /**
     * The Entity Manager.
     *
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * The plugin logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * @param EntityManagerInterface $entityManager The Entity Manager.
     * @param LoggerInterface        $pluginLogger  The plugin version of the logger interface.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->logger        = $pluginLogger;
        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


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


}//end class
