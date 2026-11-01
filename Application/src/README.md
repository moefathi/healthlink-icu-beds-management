# HealthLink

Place the `ICU` folder in `C:\xampp\htdocs`, start Apache and open **http://localhost/ICU/**.

Use PHP 8.2 with OCI8, MongoDB and cURL enabled. OCI8 needs a compatible Oracle Instant Client. Restart Apache after changing PHP extensions.

Copy `config.example.php` to `config.php` and enter the existing database settings:

- Oracle username, password and service DSN, such as `localhost:1521/XEPDB1`.
- MongoDB URI and database containing `Stay_Info`.
- Neo4j username, password and HTTP endpoint for the hospital graph, such as `http://localhost:7474/db/hospitals/tx/commit`.

Start all three databases. The development copy can use its existing `.env` when `config.php` is absent. Credentials and database dumps are excluded from the submission.

Oracle stores the operational records and controls bed/equipment status through its existing triggers and procedures. Hospital 554 uses the supplied bed and outgoing-transfer views where applicable; other hospitals use base tables. Admin's Hospital Charges section reads the existing charge materialized view.

End Stay locks the stay and bed, checks the Staff hospital and leaving date, and calls `FINISHSTAY_PRC` when valid. If the procedure is unavailable, it updates only the active stay's leaving date and commits after success. Existing Oracle triggers release the bed and equipment; assignment history remains.

MongoDB stores medical information in `Stay_Info` using the Oracle stay ID. Neo4j stores minimum-hop hospital routes and transfer history.

New Staff sessions select hospital #445 when it exists. Staff can switch hospitals in the top bar and search the current hospital's records. Patients remain network-wide. Admin displays global data. Staff and Admin are interface modes only.

Admin selects an admission hospital before searching patients and choosing one of its available beds. Both modes lock the patient and bed and reject a second active stay anywhere in the network. Completed stays do not prevent later admission.

Admin Global Capacity shows separate bed, active-stay and equipment counts for every hospital, with hospital ID/name search. Equipment's Current Assignments page connects each in-use item to its active stay, actual bed and hospital. Equipment history includes completed stays and their beds and hospitals.
