<?php

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Locks the account row in MySQL using SELECT ... FOR UPDATE
     */
    public function findByIdForUpdate(string $id): ?Account
    {
        return $this->find($id, LockMode::PESSIMISTIC_WRITE);
    }
}
