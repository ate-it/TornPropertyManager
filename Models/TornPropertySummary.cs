namespace TornPropertyManager.Models;

public class TornPropertySummary
{
    public int PropertyId { get; set; }
    public string Name { get; set; } = "";
    public string Type { get; set; } = "";
    public bool IsRented { get; set; }
    public string Status { get; set; } = "";
}
